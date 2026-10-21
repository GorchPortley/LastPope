<?php

use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Exceptions\Marketplace\CheckoutError;
use App\Exceptions\Marketplace\CheckoutException;
use App\Models\Order;
use App\Models\User;
use App\Notifications\NewSale;
use App\Notifications\OrderRefunded;
use App\Notifications\OrderReceipt;
use App\Notifications\SaleCancelled;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\CheckoutService;
use App\Services\Marketplace\OrderFulfillment;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config([
        'marketplace.fee_bps' => 1000,
        'marketplace.reservation_minutes' => 30,
        'marketplace.physical_payout_trigger' => 'shipped',
        'marketplace.stripe.marketplace_webhook_secret' => 'whsec_reservation',
    ]);
    $this->buyerA = User::factory()->create();
    $this->buyerB = User::factory()->create();
    $this->seller = onboardedSeller();
    $this->listing = sellableListing($this->seller, 6000, ['quantity' => 1, 'shipping_domestic_cents' => 800, 'additional_item_shipping_cents' => 0]);
    $this->carts = app(CartService::class);
    $this->checkout = app(CheckoutService::class);
});

function checkoutError(callable $callback): ?CheckoutError
{
    try {
        $callback();
    } catch (CheckoutException $e) {
        return $e->error;
    }

    return null;
}

// --- Reservation ---------------------------------------------------------------------------------

it('holds the stock of a started checkout and releases it when the hold expires', function () {
    $this->carts->add($this->buyerA, $this->listing);
    $this->carts->add($this->buyerB, $this->listing);
    $addressA = addressFor($this->buyerA);
    $addressB = addressFor($this->buyerB);

    $orderA = $this->checkout->start($this->buyerA, $addressA)->order;

    expect($orderA->reserved_until->between(now()->addMinutes(29), now()->addMinutes(31)))->toBeTrue()
        ->and(fakeGateway()->lastCall('createCheckoutSession')['reservedUntil'])->toBe($orderA->reserved_until->getTimestamp())
        ->and($this->listing->availableQuantity())->toBe(0)
        ->and($this->listing->availableQuantity($this->buyerA))->toBe(1) // their own order does not block them
        ->and($this->listing->fresh()->quantity)->toBe(1);

    // the second buyer sees the item as held, in the cart and at checkout
    $summary = $this->carts->summary($this->buyerB, $addressB);
    expect($summary->canCheckout())->toBeFalse()
        ->and($summary->errors()[0])->toContain('on hold');
    expect(checkoutError(fn () => $this->checkout->start($this->buyerB, $addressB)))->toBe(CheckoutError::INSUFFICIENT_STOCK);
    expect(Order::where('user_id', $this->buyerB->id)->count())->toBe(0);

    // the first buyer can retry (back button from Stripe) without being blocked by themselves
    expect($this->checkout->start($this->buyerA, $addressA)->order->status)->toBe(OrderStatus::PENDING);

    // after the hold ran out the second buyer can have it
    $this->travel(31)->minutes();
    expect($this->listing->availableQuantity())->toBe(1)
        ->and($this->checkout->start($this->buyerB, $addressB)->order->status)->toBe(OrderStatus::PENDING);
});

it('only holds the units that were ordered', function () {
    $listing = sellableListing($this->seller, 1000, ['quantity' => 3]);
    $this->carts->add($this->buyerA, $listing, 2);
    $this->carts->add($this->buyerB, $listing, 1);
    $this->checkout->start($this->buyerA, addressFor($this->buyerA));

    expect($listing->availableQuantity())->toBe(1);

    // B may still take the remaining unit but not two
    expect($this->carts->updateQuantity($this->buyerB, $this->buyerB->cart->items()->first(), 2)->outcome->value)->toBe('insufficient_stock');
    $order = $this->checkout->start($this->buyerB, addressFor($this->buyerB))->order;

    expect($order->items->first()->quantity)->toBe(1)
        ->and($listing->availableQuantity())->toBe(0);
});

it('does not let a buyer add a held item to the cart, with a clear message', function () {
    $this->carts->add($this->buyerA, $this->listing);
    $this->checkout->start($this->buyerA, addressFor($this->buyerA));

    $result = $this->carts->add($this->buyerB, $this->listing);

    expect($result->ok())->toBeFalse()
        ->and($result->message())->toContain('on hold')
        ->and($this->listing->fresh()->isAvailableForPurchase())->toBeFalse()
        ->and($this->listing->fresh()->isAvailableForPurchase($this->buyerA))->toBeTrue();
});

it('stops holding stock once the order is cancelled, and digital orders hold none', function () {
    $this->carts->add($this->buyerA, $this->listing);
    $order = $this->checkout->start($this->buyerA, addressFor($this->buyerA))->order;
    expect($this->listing->availableQuantity())->toBe(0);

    app(OrderFulfillment::class)->cancelPending($order);
    expect($this->listing->availableQuantity())->toBe(1);

    $design = paidDesign(cents: 1000);
    $digital = pendingOrder($this->buyerB, [$design]);
    expect($digital->reserved_until)->not->toBeNull(); // the Checkout Session still expires with it
});

it('frees the stock when Stripe says the checkout session expired', function () {
    $this->carts->add($this->buyerA, $this->listing);
    $this->carts->add($this->buyerB, $this->listing);
    $orderA = $this->checkout->start($this->buyerA, addressFor($this->buyerA))->order;
    $addressB = addressFor($this->buyerB);

    expect(checkoutError(fn () => $this->checkout->start($this->buyerB, $addressB)))->toBe(CheckoutError::INSUFFICIENT_STOCK);

    postStripeEvent($this, 'stripe.marketplace.webhook', stripeEventPayload('checkout.session.expired', checkoutSessionObject($orderA, 'unpaid')), 'whsec_reservation')->assertOk();

    expect($orderA->fresh()->status)->toBe(OrderStatus::CANCELLED)
        ->and($this->listing->availableQuantity())->toBe(1)
        ->and($this->checkout->start($this->buyerB, $addressB)->order->status)->toBe(OrderStatus::PENDING);
});

it('clamps the reservation to the 30 minute to 24 hour window Stripe accepts', function (int $configured, int $expected) {
    config(['marketplace.reservation_minutes' => $configured]);
    fillCart($this->buyerA, [paidDesign(cents: 1000)]);

    $order = startCheckout($this->buyerA)->order;

    expect(Order::reservationMinutes())->toBe($expected)
        ->and((int) round(now()->diffInMinutes($order->reserved_until, true)))->toBe($expected);
})->with([
    'default' => [30, 30],
    'too short' => [5, 30],
    'zero' => [0, 30],
    'longer' => [90, 90],
    'too long' => [99999, 1440],
]);

it('cancels pending orders whose hold ended long ago, and only those', function () {
    $this->carts->add($this->buyerA, $this->listing);
    $old = $this->checkout->start($this->buyerA, addressFor($this->buyerA))->order;
    $recent = pendingOrder($this->buyerB, [paidDesign(cents: 1000)]);
    $paid = pendingOrder(User::factory()->create(), [paidDesign(cents: 1000)]);
    payOrder($paid, 'pi_x', 'ch_x');

    $old->forceFill(['reserved_until' => now()->subHours(3)])->save();
    $recent->forceFill(['reserved_until' => now()->subMinutes(45)])->save();

    $this->artisan('marketplace:cleanup-orders')->expectsOutputToContain('Cancelled 1 abandoned')->assertSuccessful();

    expect($old->fresh()->status)->toBe(OrderStatus::CANCELLED)
        ->and($old->sellerOrders()->first()->status)->toBe(SellerOrderStatus::CANCELLED)
        ->and($recent->fresh()->status)->toBe(OrderStatus::PENDING)
        ->and($paid->fresh()->status)->toBe(OrderStatus::COMPLETED);
});

it('is scheduled hourly', function () {
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events());

    expect($events->contains(fn ($event) => str_contains($event->command, 'marketplace:cleanup-orders') && $event->expression === '0 * * * *'))->toBeTrue();
});

// --- Overselling safety net -----------------------------------------------------------------------

/** Buyer A holds and pays for the last unit; buyer B's hold lapsed, B checks out anyway and pays too. */
function raceForLastUnit(object $test, array $bExtras = []): array
{
    $addressA = addressFor($test->buyerA);
    $addressB = addressFor($test->buyerB);

    $test->carts->add($test->buyerA, $test->listing);
    $orderA = $test->checkout->start($test->buyerA, $addressA)->order;

    // A's hold lapses, B starts a checkout (also for something else), then both pay.
    $test->travel(31)->minutes();
    $test->carts->add($test->buyerB, $test->listing);
    foreach ($bExtras as $extra) {
        $test->carts->add($test->buyerB, $extra);
    }
    $orderB = $test->checkout->start($test->buyerB, $addressB)->order;

    payOrder($orderA, 'pi_a', 'ch_a');
    payOrder($orderB, 'pi_b', 'ch_b');

    return [$orderA->fresh(), $orderB->fresh()];
}

it('refunds the buyer automatically when the last unit was already sold by the time they paid', function () {
    Notification::fake();
    $design = paidDesign(onboardedSeller(), 2000);

    [$orderA, $orderB] = raceForLastUnit($this, [$design]);

    // A got the unit
    expect($orderA->status)->toBe(OrderStatus::PAID)
        ->and($this->listing->fresh()->quantity)->toBe(0)
        ->and($this->listing->fresh()->status)->toBe(ListingStatus::SOLD_OUT);

    // B: the physical part is refunded (partial: items + shipping of that seller order), the digital part delivered
    $listingOrder = $orderB->sellerOrders()->where('seller_id', $this->seller->id)->first();
    $designOrder = $orderB->sellerOrders()->where('seller_id', $design->designer->id)->first();

    $refund = collect(fakeGateway()->calls('refund'))->firstWhere('args.paymentIntentId', 'pi_b')['args'];
    expect($listingOrder->status)->toBe(SellerOrderStatus::REFUNDED)
        ->and($listingOrder->refunded_cents)->toBe(6800)
        ->and($refund['amountCents'])->toBe(6800)
        ->and($refund['meta']['refunded_by'])->toBe('system')
        ->and($listingOrder->isTransferred())->toBeFalse()
        ->and($designOrder->status)->toBe(SellerOrderStatus::COMPLETED)
        ->and($designOrder->isTransferred())->toBeTrue()
        ->and($this->buyerB->hasAccessTo($design, false))->toBeTrue()
        ->and($orderB->status)->toBe(OrderStatus::PARTIALLY_REFUNDED);

    // nobody was paid for the unit B could not get; B's seller got A's payout only when shipped
    expect(collect(fakeGateway()->calls('createTransfer'))->every(fn ($c) => $c['args']['meta']['seller_order_id'] !== $listingOrder->id))->toBeTrue();

    Notification::assertSentTo($this->buyerB, OrderRefunded::class, fn ($n) => $n->amountCents === 6800 && str_contains($n->note, 'no longer in stock'));
    Notification::assertSentTo($this->seller, SaleCancelled::class, 1);
    Notification::assertSentToTimes($this->seller, NewSale::class, 1); // only A's sale
    Notification::assertSentTo($design->designer, NewSale::class);
    Notification::assertSentTo($this->buyerB, OrderReceipt::class);
});

it('refunds the whole payment when nothing in the order could be fulfilled', function () {
    [, $orderB] = raceForLastUnit($this);

    $refund = collect(fakeGateway()->calls('refund'))->firstWhere('args.paymentIntentId', 'pi_b')['args'];
    expect($refund['amountCents'])->toBeNull() // the entire payment
        ->and($orderB->status)->toBe(OrderStatus::REFUNDED)
        ->and($orderB->sellerOrders()->first()->status)->toBe(SellerOrderStatus::REFUNDED)
        ->and($this->listing->fresh()->quantity)->toBe(0);
});

it('refunds a paid line when the seller lowered the stock after checkout', function () {
    $listing = sellableListing($this->seller, 1000, ['quantity' => 2, 'shipping_domestic_cents' => 800, 'additional_item_shipping_cents' => 300]);
    $order = pendingOrder($this->buyerA, [[$listing, 2]], addressFor($this->buyerA));

    $listing->update(['quantity' => 1]); // the seller sold one elsewhere
    payOrder($order, 'pi_low', 'ch_low');

    expect($order->fresh()->status)->toBe(OrderStatus::REFUNDED)
        ->and($listing->fresh()->quantity)->toBe(1) // untouched: nothing was taken
        ->and($order->sellerOrders()->first()->refunded_cents)->toBe(2000 + 1100); // 2 items + shipping (800 + 300)
});

it('never pays out an unfulfillable seller order, even when its refund is delayed', function () {
    config(['marketplace.physical_payout_trigger' => 'paid']);
    $listing = sellableListing($this->seller, 1000, ['quantity' => 1]);
    $order = pendingOrder($this->buyerA, [$listing], addressFor($this->buyerA));
    $listing->update(['quantity' => 0, 'status' => ListingStatus::PAUSED]);
    fakeGateway()->failOn('refund');

    payOrder($order, 'pi_late', 'ch_late');

    $sellerOrder = $order->sellerOrders()->first();
    expect($sellerOrder->status)->toBe(SellerOrderStatus::PAID) // stuck, waiting for its refund
        ->and($sellerOrder->isTransferred())->toBeFalse()
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::PAID);

    // the hourly job (or a redelivered webhook) completes the refund
    $this->artisan('marketplace:cleanup-orders')->expectsOutputToContain('refunded 1 unfulfillable')->assertSuccessful();

    expect($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::REFUNDED)
        ->and($order->fresh()->status)->toBe(OrderStatus::REFUNDED)
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(0);
});

it('retries the automatic refund when the payment event is delivered again', function () {
    $listing = sellableListing($this->seller, 1000, ['quantity' => 1]);
    $order = pendingOrder($this->buyerA, [$listing], addressFor($this->buyerA));
    $listing->update(['quantity' => 0]);
    fakeGateway()->failOn('refund');

    payOrder($order, 'pi_again', 'ch_again');
    expect($order->sellerOrders()->first()->status)->toBe(SellerOrderStatus::PAID);

    expect(payOrder($order->fresh(), 'pi_again', 'ch_again'))->toBeFalse();

    expect($order->sellerOrders()->first()->status)->toBe(SellerOrderStatus::REFUNDED);
});

it('keeps the refund ability closed to normal users while the system path stays open', function () {
    $listing = sellableListing($this->seller, 1000, ['quantity' => 5]);
    $order = pendingOrder($this->buyerA, [$listing], addressFor($this->buyerA));
    payOrder($order, 'pi_auth', 'ch_auth');
    $sellerOrder = $order->sellerOrders()->first();
    $refunds = app(App\Services\Marketplace\RefundService::class);

    expect(fn () => $refunds->refundSellerOrder($this->buyerA, $sellerOrder))->toThrow(Illuminate\Auth\Access\AuthorizationException::class)
        ->and(fn () => $refunds->refundSellerOrder($this->seller, $sellerOrder))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);

    expect($refunds->refundSellerOrderAutomatically($sellerOrder, 'Because.')->amountCents)->toBeGreaterThan(0);
});

it('makes the Stripe Checkout Session expire with the stock hold', function () {
    $http = Tests\Support\FakeStripeHttpClient::install();
    $http->respond('POST /v1/checkout/sessions', ['id' => 'cs_exp', 'object' => 'checkout.session', 'url' => 'https://x']);
    $gateway = new App\Services\Payments\StripeGateway('sk_test_unit');

    $order = Order::factory()->create(['reserved_until' => now()->addMinutes(30)]);
    $gateway->createCheckoutSession($order, [], 'https://s', 'https://c');
    $short = (int) $http->last('POST /v1/checkout/sessions')['params']['expires_at'];

    $long = Order::factory()->create(['reserved_until' => now()->addDays(3)]);
    $gateway->createCheckoutSession($long, [], 'https://s', 'https://c');
    $capped = (int) $http->last('POST /v1/checkout/sessions')['params']['expires_at'];

    Tests\Support\FakeStripeHttpClient::uninstall();

    // never less than 30 minutes from now (Stripe rejects it), never more than 24 hours
    expect($short)->toBeGreaterThanOrEqual(time() + 30 * 60)
        ->and($short)->toBeLessThanOrEqual(time() + 32 * 60)
        ->and($capped)->toBeLessThan(time() + 24 * 3600);
});
