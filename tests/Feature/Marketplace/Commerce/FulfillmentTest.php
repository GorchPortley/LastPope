<?php

use App\Enums\EntitlementSource;
use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\User;
use App\Notifications\NewSale;
use App\Notifications\OrderReceipt;
use App\Notifications\PayoutSent;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\PayoutService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['marketplace.fee_bps' => 1000, 'marketplace.fee_fixed_cents' => 0, 'marketplace.physical_payout_trigger' => 'shipped']);
    $this->buyer = User::factory()->create();
});

/** Digital from A, a data pack from B, a physical listing from C, a design from the platform. */
function mixedOrder(User $buyer, array $listingAttributes = []): array
{
    $admin = User::factory()->admin()->create();
    $sellerA = onboardedSeller();
    $sellerB = onboardedSeller();
    $sellerC = onboardedSeller();

    $things = [
        'design' => paidDesign($sellerA, 2000),
        'pack' => paidDriverPack($sellerB, 700),
        'listing' => sellableListing($sellerC, 8000, array_merge(['quantity' => 3, 'shipping_domestic_cents' => 900], $listingAttributes)),
        'house' => paidDesign($admin, 1500),
    ];

    $order = pendingOrder($buyer, [$things['design'], $things['pack'], [$things['listing'], 2], $things['house']], addressFor($buyer));

    return $things + ['order' => $order, 'sellerA' => $sellerA, 'sellerB' => $sellerB, 'sellerC' => $sellerC, 'admin' => $admin];
}

it('fulfils a paid multi-seller order', function () {
    Notification::fake();
    $unrelated = paidDesign();
    $x = mixedOrder($this->buyer);
    fillCart($this->buyer, [$unrelated]); // added after checkout started: must stay in the cart

    expect(payOrder($x['order'], 'pi_abc', 'ch_abc'))->toBeTrue();

    $order = $x['order']->fresh(['sellerOrders.items']);
    expect($order->status)->toBe(OrderStatus::PAID)
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->stripe_charge_id)->toBe('ch_abc')
        ->and($order->stripe_payment_intent_id)->toBe('pi_abc');

    // entitlements for every digital item, from the purchase
    foreach (['design', 'pack', 'house'] as $key) {
        $entitlement = $this->buyer->entitlementFor($x[$key]);
        expect($entitlement)->not->toBeNull()
            ->and($entitlement->isActive())->toBeTrue()
            ->and($entitlement->source)->toBe(EntitlementSource::PURCHASE)
            ->and($entitlement->order_item_id)->not->toBeNull();
    }
    expect($this->buyer->hasAccessTo($x['design']))->toBeTrue();

    // stock
    expect($x['listing']->fresh()->quantity)->toBe(1)->and($x['listing']->fresh()->status)->toBe(ListingStatus::ACTIVE);

    // seller order statuses
    $statuses = $order->sellerOrders->mapWithKeys(fn ($so) => [$so->seller_id => $so->status]);
    expect($statuses[$x['sellerA']->id])->toBe(SellerOrderStatus::COMPLETED)
        ->and($statuses[$x['sellerB']->id])->toBe(SellerOrderStatus::COMPLETED)
        ->and($statuses[$x['admin']->id])->toBe(SellerOrderStatus::COMPLETED)
        ->and($statuses[$x['sellerC']->id])->toBe(SellerOrderStatus::AWAITING_SHIPMENT);

    // the cart keeps only what was not ordered
    $cart = app(CartService::class)->summary($this->buyer);
    expect(collect($cart->lines)->map->title->all())->toBe([$unrelated->name]);

    // payouts: digital sellers now, the physical seller on shipment, nothing for the platform
    $transfers = fakeGateway()->calls('createTransfer');
    expect($transfers)->toHaveCount(2);
    $byDestination = collect($transfers)->keyBy(fn ($c) => $c['args']['destination']);
    $soA = $order->sellerOrders->firstWhere('seller_id', $x['sellerA']->id)->fresh();
    $soB = $order->sellerOrders->firstWhere('seller_id', $x['sellerB']->id)->fresh();
    $soC = $order->sellerOrders->firstWhere('seller_id', $x['sellerC']->id)->fresh();
    $soHouse = $order->sellerOrders->firstWhere('seller_id', $x['admin']->id)->fresh();

    expect($byDestination[$x['sellerA']->sellerProfile->stripe_account_id]['args'])->toMatchArray([
        'amountCents' => 1800, 'transferGroup' => $order->number, 'sourceCharge' => 'ch_abc',
    ])
        ->and($byDestination[$x['sellerB']->sellerProfile->stripe_account_id]['args']['amountCents'])->toBe(630)
        ->and($soA->stripe_transfer_id)->not->toBeNull()
        ->and($soA->transferred_at)->not->toBeNull()
        ->and($soB->isTransferred())->toBeTrue()
        ->and($soC->isTransferred())->toBeFalse()
        ->and($soHouse->isTransferred())->toBeFalse()
        ->and($soHouse->payout_cents)->toBe(0);

    // notifications: receipt to the buyer, new sale to each of the four sellers, payout to the two paid
    Notification::assertSentTo($this->buyer, OrderReceipt::class, 1);
    foreach (['sellerA', 'sellerB', 'sellerC', 'admin'] as $key) {
        Notification::assertSentTo($x[$key], NewSale::class, 1);
    }
    Notification::assertSentTo($x['sellerA'], PayoutSent::class, 1);
    Notification::assertSentTo($x['sellerB'], PayoutSent::class, 1);
    Notification::assertNotSentTo($x['sellerC'], PayoutSent::class);
});

it('is idempotent: a second call changes nothing and pays nobody twice', function () {
    Notification::fake();
    $x = mixedOrder($this->buyer);

    expect(payOrder($x['order']))->toBeTrue()
        ->and(payOrder($x['order']))->toBeFalse()
        ->and(payOrder($x['order']->fresh(), 'pi_other', 'ch_other'))->toBeFalse();

    expect(Entitlement::where('user_id', $this->buyer->id)->count())->toBe(3)
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(2)
        ->and($x['listing']->fresh()->quantity)->toBe(1)
        ->and($x['order']->fresh()->stripe_charge_id)->toBe('ch_test_1')
        ->and($x['order']->fresh()->stripe_payment_intent_id)->toBe('pi_test_1');

    Notification::assertSentToTimes($this->buyer, OrderReceipt::class, 1);
    Notification::assertSentToTimes($x['sellerC'], NewSale::class, 1);
});

it('marks a listing sold out when the last unit is bought', function () {
    $listing = sellableListing(attributes: ['quantity' => 2]);
    $order = pendingOrder($this->buyer, [[$listing, 2]], addressFor($this->buyer));

    payOrder($order);

    expect($listing->fresh()->quantity)->toBe(0)->and($listing->fresh()->status)->toBe(ListingStatus::SOLD_OUT);
});

it('completes an order that only contains digital items', function () {
    Notification::fake();
    $order = pendingOrder($this->buyer, [paidDesign(cents: 1000), paidDriverPack(cents: 500)]);

    payOrder($order);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::COMPLETED)
        ->and($order->sellerOrders->every(fn ($so) => $so->status === SellerOrderStatus::COMPLETED && $so->isTransferred()))->toBeTrue();
});

it('also transfers physical orders on payment when the payout trigger is "paid"', function () {
    config(['marketplace.physical_payout_trigger' => 'paid']);
    $x = mixedOrder($this->buyer);

    payOrder($x['order']);

    $soC = $x['order']->sellerOrders()->where('seller_id', $x['sellerC']->id)->first();
    expect(fakeGateway()->callCount('createTransfer'))->toBe(3)
        ->and($soC->isTransferred())->toBeTrue()
        ->and($soC->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT)
        // 2 x 8000 items + (900 + 1 x 300 additional unit) shipping - 10 % of the items
        ->and($soC->payout_cents)->toBe(16000 + 1200 - 1600);
});

it('holds the physical payout back until shipment when the trigger is "shipped"', function () {
    $x = mixedOrder($this->buyer);
    payOrder($x['order']);

    $soC = $x['order']->sellerOrders()->where('seller_id', $x['sellerC']->id)->first();
    $payouts = app(PayoutService::class);

    expect($payouts->isDue($soC))->toBeFalse()
        ->and($payouts->transferIfDue($soC))->toBeNull()
        ->and($soC->fresh()->isTransferred())->toBeFalse();
});

it('retries a failed payout when the event is delivered again', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 2000)]);
    fakeGateway()->failOn('createTransfer');

    expect(payOrder($order))->toBeTrue(); // fulfilled, but the transfer failed
    $sellerOrder = $order->sellerOrders()->first();
    expect($sellerOrder->isTransferred())->toBeFalse()
        ->and($this->buyer->hasAccessTo($sellerOrder->items->first()->purchasable))->toBeTrue();

    expect(payOrder($order->fresh()))->toBeFalse(); // redelivery: no second fulfilment, payout picked up

    expect($sellerOrder->fresh()->isTransferred())->toBeTrue()
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(2);
});

it('releases due payouts in bulk and does not pay twice', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 2000)]);
    fakeGateway()->failOn('createTransfer');
    payOrder($order);

    $payouts = app(PayoutService::class);

    expect($payouts->releaseDue())->toBe(1)
        ->and($payouts->releaseDue())->toBe(0)
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(2); // one failed attempt, one success
});

it('does not pay out sellers without a connected account but keeps the order fulfilled', function () {
    $seller = onboardedSeller();
    $design = paidDesign($seller, 2000);
    $order = pendingOrder($this->buyer, [$design]);
    $seller->sellerProfile->update(['stripe_account_id' => null]);

    payOrder($order);

    expect($order->sellerOrders()->first()->isTransferred())->toBeFalse()
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::COMPLETED);
});

it('fulfils an order that Stripe reports as paid even if it was cancelled meanwhile', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 1000)]);
    app(App\Services\Marketplace\OrderFulfillment::class)->cancelPending($order);
    expect($order->fresh()->status)->toBe(OrderStatus::CANCELLED);

    expect(payOrder($order->fresh()))->toBeTrue();

    expect($order->fresh()->status)->toBe(OrderStatus::COMPLETED);
});

it('does not cancel an order that is already paid', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 1000)]);
    payOrder($order);

    expect(app(App\Services\Marketplace\OrderFulfillment::class)->cancelPending($order->fresh()))->toBeFalse()
        ->and($order->fresh()->status)->toBe(OrderStatus::COMPLETED);
});

it('keeps the purchased entitlement when the buyer had a free one', function () {
    $design = paidDesign(cents: 1000);
    // e.g. the design was free when the buyer collected it, then became paid
    $this->buyer->grantEntitlement($design, EntitlementSource::FREE)->revoke();
    $order = pendingOrder($this->buyer, [$design]);

    payOrder($order);

    $entitlement = $this->buyer->entitlementFor($design);
    expect($entitlement->isActive())->toBeTrue()->and($entitlement->source)->toBe(EntitlementSource::PURCHASE);
});

it('queues receipt and sale mails with the order details', function () {
    $x = mixedOrder($this->buyer);
    payOrder($x['order']);

    $order = $x['order']->fresh(['items', 'sellerOrders.items.order']);
    $mail = (new OrderReceipt($order))->toMail($this->buyer);
    $text = implode("\n", array_merge($mail->introLines, $mail->outroLines));

    expect($mail->subject)->toBe('Your order '.$order->number)
        ->and($text)->toContain($order->items->first()->title)
        ->and($text)->toContain(money($order->total_cents))
        ->and((new OrderReceipt($order))->via($this->buyer))->toBe(['mail', 'database'])
        ->and((new OrderReceipt($order))->toArray($this->buyer))->toHaveKeys(['body', 'link', 'icon', 'user']);

    $sale = $order->sellerOrders->firstWhere('seller_id', $x['sellerC']->id);
    $saleText = implode("\n", array_merge((new NewSale($sale))->toMail($x['sellerC'])->introLines, (new NewSale($sale))->toMail($x['sellerC'])->outroLines));
    expect($saleText)->toContain('Please ship to');
});
