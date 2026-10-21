<?php

use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Exceptions\Marketplace\CheckoutError;
use App\Exceptions\Marketplace\CheckoutException;
use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use App\Services\Marketplace\CheckoutService;

beforeEach(function () {
    config(['marketplace.fee_bps' => 1000, 'marketplace.fee_fixed_cents' => 0, 'marketplace.stripe_tax' => false]);
    $this->buyer = User::factory()->create(['email' => 'buyer@example.test']);
    $this->checkout = app(CheckoutService::class);
});

/** The multi-seller cart from the brief: design (A), a second design (B), two used listings (C), platform design. */
function multiSellerCart(User $buyer): array
{
    $admin = User::factory()->admin()->create();
    $sellerA = onboardedSeller();
    $sellerB = onboardedSeller();
    $sellerC = onboardedSeller();

    $items = [
        'design' => paidDesign($sellerA, 2000),
        'pack' => paidDesign($sellerB, 700),
        'woofer' => sellableListing($sellerC, 12000, [
            'title' => 'Used Peerless woofer', 'condition' => 'used_good', 'quantity' => 1,
            'shipping_domestic_cents' => 1500, 'shipping_international_cents' => 3000, 'additional_item_shipping_cents' => 0,
        ]),
        'tweeters' => sellableListing($sellerC, 5000, [
            'title' => 'Used tweeter pair', 'condition' => 'used_like_new', 'quantity' => 3, 'units_per_lot' => 2,
            'shipping_domestic_cents' => 900, 'shipping_international_cents' => 1800, 'additional_item_shipping_cents' => 700,
        ]),
        'house' => paidDesign($admin, 1500),
    ];

    fillCart($buyer, [$items['design'], $items['pack'], $items['woofer'], [$items['tweeters'], 2], $items['house']]);

    return $items + ['sellerA' => $sellerA, 'sellerB' => $sellerB, 'sellerC' => $sellerC, 'admin' => $admin];
}

it('creates the whole order tree for a multi-seller cart and sends the right lines to the gateway', function () {
    $cart = multiSellerCart($this->buyer);
    $address = addressFor($this->buyer, 'DE');

    $result = $this->checkout->start($this->buyer, $address);
    $order = $result->order->load('sellerOrders.items');

    // order
    expect($order->status)->toBe(OrderStatus::PENDING)
        ->and($order->user_id)->toBe($this->buyer->id)
        ->and($order->number)->toStartWith('SDL-')
        ->and($order->currency)->toBe('usd')
        ->and($order->subtotal_cents)->toBe(2000 + 700 + 12000 + 10000 + 1500)
        ->and($order->shipping_cents)->toBe(3000 + 1800 + 700)
        ->and($order->tax_cents)->toBe(0)
        ->and($order->total_cents)->toBe(26200 + 5500)
        ->and($order->platform_fee_cents)->toBe(200 + 70 + 2200 + 0)
        ->and($order->stripe_checkout_session_id)->toBe($result->sessionId)
        ->and($result->url)->toStartWith('https://checkout.stripe.test/')
        ->and($order->shipping_address)->toMatchArray($address->toSnapshot());

    // seller orders
    expect($order->sellerOrders)->toHaveCount(4);
    $byseller = $order->sellerOrders->keyBy('seller_id');

    $a = $byseller[$cart['sellerA']->id];
    expect($a->status)->toBe(SellerOrderStatus::PENDING)
        ->and([$a->subtotal_cents, $a->shipping_cents, $a->platform_fee_cents, $a->payout_cents])->toBe([2000, 0, 200, 1800]);

    $b = $byseller[$cart['sellerB']->id];
    expect([$b->subtotal_cents, $b->shipping_cents, $b->platform_fee_cents, $b->payout_cents])->toBe([700, 0, 70, 630]);

    $c = $byseller[$cart['sellerC']->id];
    expect($c->items)->toHaveCount(2)
        ->and([$c->subtotal_cents, $c->shipping_cents, $c->platform_fee_cents, $c->payout_cents])->toBe([22000, 5500, 2200, 22000 + 5500 - 2200]);

    $house = $byseller[$cart['admin']->id];
    expect([$house->subtotal_cents, $house->platform_fee_cents, $house->payout_cents])->toBe([1500, 0, 0]);

    // order items: snapshots, line totals exclude shipping
    expect($order->items)->toHaveCount(5);
    $tweeters = $order->items->firstWhere('title', 'Used tweeter pair');
    expect($tweeters->purchasable_type)->toBe('listing')
        ->and($tweeters->is_digital)->toBeFalse()
        ->and([$tweeters->quantity, $tweeters->unit_price_cents, $tweeters->shipping_cents, $tweeters->line_total_cents])->toBe([2, 5000, 1800 + 700, 10000]);
    $design = $order->items->firstWhere('purchasable_type', 'design');
    expect($design->is_digital)->toBeTrue()->and($design->shipping_cents)->toBe(0);

    // gateway: one call, line items add up to the order total, one shipping line (seller C only)
    $gateway = fakeGateway();
    expect($gateway->callCount('createCheckoutSession'))->toBe(1);
    $call = $gateway->lastCall('createCheckoutSession');
    expect($call['order_number'])->toBe($order->number)
        ->and($call['successUrl'])->toBe(route('checkout.success').'?session_id={CHECKOUT_SESSION_ID}')
        ->and($call['cancelUrl'])->toBe(route('checkout.cancel').'?order='.$order->number);

    $lines = collect($call['lineItems']);
    expect($lines)->toHaveCount(6)
        ->and($lines->sum(fn ($l) => $l['price_data']['unit_amount'] * $l['quantity']))->toBe($order->total_cents)
        ->and($lines->every(fn ($l) => $l['price_data']['currency'] === 'usd'))->toBeTrue();

    $shippingLines = $lines->filter(fn ($l) => str_starts_with($l['price_data']['product_data']['name'], 'Shipping'));
    expect($shippingLines)->toHaveCount(1)
        ->and($shippingLines->first()['price_data']['unit_amount'])->toBe(5500);

    $tweeterLine = $lines->first(fn ($l) => $l['price_data']['product_data']['name'] === 'Used tweeter pair');
    expect($tweeterLine['quantity'])->toBe(2)
        ->and($tweeterLine['price_data']['unit_amount'])->toBe(5000)
        ->and($tweeterLine['price_data']['product_data']['description'])->toContain('Lot of 2');

    // the cart is untouched until the payment succeeds
    expect(app(App\Services\Marketplace\CartService::class)->count($this->buyer))->toBe(6);
});

it('needs no address for a digital cart and stores none', function () {
    fillCart($this->buyer, [paidDesign(cents: 1000)]);

    $order = $this->checkout->start($this->buyer, addressFor($this->buyer))->order;

    expect($order->shipping_address)->toBeNull()
        ->and($order->shipping_cents)->toBe(0)
        ->and($order->total_cents)->toBe(1000);

    // and works without any
    $buyer2 = User::factory()->create();
    fillCart($buyer2, [paidDesign(cents: 500)]);
    expect($this->checkout->start($buyer2)->order->total_cents)->toBe(500);
});

it('refuses an empty cart', function () {
    expect(fn () => $this->checkout->start($this->buyer))
        ->toThrow(fn (CheckoutException $e) => $e->error === CheckoutError::EMPTY_CART);

    expect(Order::where('user_id', $this->buyer->id)->count())->toBe(0)->and(fakeGateway()->callCount('createCheckoutSession'))->toBe(0);
});

it('requires an address only when the cart has shippable items', function () {
    fillCart($this->buyer, [sellableListing()]);

    expect(fn () => $this->checkout->start($this->buyer))
        ->toThrow(fn (CheckoutException $e) => $e->error === CheckoutError::ADDRESS_REQUIRED);

    expect(Order::where('user_id', $this->buyer->id)->count())->toBe(0);
});

it('rejects an address that is not the buyers or is incomplete', function () {
    fillCart($this->buyer, [sellableListing()]);
    $foreign = addressFor(User::factory()->create());
    $incomplete = addressFor($this->buyer);
    $incomplete->postal_code = '';

    expect(fn () => $this->checkout->start($this->buyer, $foreign))
        ->toThrow(fn (CheckoutException $e) => $e->error === CheckoutError::ADDRESS_INVALID);
    expect(fn () => $this->checkout->start($this->buyer, $incomplete))
        ->toThrow(fn (CheckoutException $e) => $e->error === CheckoutError::ADDRESS_INVALID);
});

it('blocks checkout when a seller does not ship to the address', function () {
    $domesticOnly = sellableListing(attributes: ['ships_from_country' => 'US', 'shipping_international_cents' => null]);
    fillCart($this->buyer, [$domesticOnly]);

    try {
        $this->checkout->start($this->buyer, addressFor($this->buyer, 'DE'));
        $this->fail('Expected a CheckoutException');
    } catch (CheckoutException $e) {
        expect($e->error)->toBe(CheckoutError::NOT_SHIPPABLE)
            ->and($e->issues)->toHaveCount(1)
            ->and($e->getMessage())->toContain('does not ship');
    }

    expect(Order::where('user_id', $this->buyer->id)->count())->toBe(0);
});

it('re-validates the cart and lists the unavailable items', function () {
    $design = paidDesign();
    $listing = sellableListing(attributes: ['quantity' => 3]);
    fillCart($this->buyer, [$design, [$listing, 3]]);

    $design->update(['active' => false]);
    $listing->update(['quantity' => 1]);

    try {
        $this->checkout->start($this->buyer, addressFor($this->buyer));
        $this->fail('Expected a CheckoutException');
    } catch (CheckoutException $e) {
        expect($e->error)->toBe(CheckoutError::UNAVAILABLE_ITEMS)
            ->and($e->issueMessages())->toHaveCount(2);
    }

    expect(Order::where('user_id', $this->buyer->id)->count())->toBe(0);
});

it('charges today\'s price, and refuses when it differs from what the buyer saw', function () {
    $design = paidDesign(cents: 2000);
    fillCart($this->buyer, [$design]);
    $seen = app(App\Services\Marketplace\CartService::class)->summary($this->buyer)->totalCents();

    $design->update(['price_cents' => 2500]);

    expect(fn () => $this->checkout->start($this->buyer, null, $seen))
        ->toThrow(fn (CheckoutException $e) => $e->error === CheckoutError::PRICE_CHANGED);

    $order = $this->checkout->start($this->buyer, null, 2500)->order;
    expect($order->total_cents)->toBe(2500)
        ->and($order->items->first()->unit_price_cents)->toBe(2500);
});

it('cancels the order and raises a typed error when the gateway fails', function () {
    fillCart($this->buyer, [paidDesign()]);
    fakeGateway()->failOn('createCheckoutSession');

    try {
        $this->checkout->start($this->buyer);
        $this->fail('Expected a CheckoutException');
    } catch (CheckoutException $e) {
        expect($e->error)->toBe(CheckoutError::GATEWAY_ERROR);
    }

    $order = Order::where('user_id', $this->buyer->id)->sole();
    expect($order->status)->toBe(OrderStatus::CANCELLED)
        ->and($order->stripe_checkout_session_id)->toBeNull()
        ->and($order->sellerOrders->every(fn ($so) => $so->status === SellerOrderStatus::CANCELLED))->toBeTrue();

    // the cart survived and a retry works
    expect($this->checkout->start($this->buyer)->order->status)->toBe(OrderStatus::PENDING);
});

it('asks Stripe Tax to compute tax when enabled', function () {
    config(['marketplace.stripe_tax' => true]);
    fillCart($this->buyer, [paidDesign(cents: 1000)]);

    $this->checkout->start($this->buyer);

    $line = fakeGateway()->lastCall('createCheckoutSession')['lineItems'][0];
    expect($line['price_data']['tax_behavior'])->toBe('exclusive');
});

it('creates a separate order per checkout and snapshots the address as it was', function () {
    fillCart($this->buyer, [sellableListing(attributes: ['quantity' => 4])]);
    $address = addressFor($this->buyer, 'US');

    $first = $this->checkout->start($this->buyer, $address)->order;
    $address->update(['line1' => 'Moved away 99']);
    $second = $this->checkout->start($this->buyer, $address->fresh())->order;

    expect($first->number)->not->toBe($second->number)
        ->and($first->fresh()->shipping_address['line1'])->not->toBe('Moved away 99')
        ->and($second->fresh()->shipping_address['line1'])->toBe('Moved away 99');
});
