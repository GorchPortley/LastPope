<?php

use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Models\User;
use App\Notifications\OrderRefunded;
use App\Services\Marketplace\RefundService;
use App\Services\Marketplace\ShipmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    config(['marketplace.fee_bps' => 1000, 'marketplace.fee_fixed_cents' => 0, 'marketplace.physical_payout_trigger' => 'shipped']);
    $this->buyer = User::factory()->create();
    $this->admin = User::factory()->admin()->create();
    $this->refunds = app(RefundService::class);
});

/** A paid two-seller order: a design from A (transferred) and a listing from B (awaiting shipment). */
function twoSellerOrder(object $test): array
{
    $sellerA = onboardedSeller();
    $sellerB = onboardedSeller();
    $design = paidDesign($sellerA, 3000);
    $listing = sellableListing($sellerB, 6000, ['shipping_domestic_cents' => 1000, 'additional_item_shipping_cents' => 0, 'quantity' => 2]);
    $order = pendingOrder($test->buyer, [$design, $listing], addressFor($test->buyer));
    payOrder($order, 'pi_refund', 'ch_refund');

    return [
        'order' => $order->fresh(),
        'design' => $design,
        'listing' => $listing,
        'sellerA' => $sellerA,
        'sellerB' => $sellerB,
        'soA' => $order->sellerOrders()->where('seller_id', $sellerA->id)->first(),
        'soB' => $order->sellerOrders()->where('seller_id', $sellerB->id)->first(),
    ];
}

it('refunds one seller order: amount, payout reversal, entitlement, statuses', function () {
    Notification::fake();
    $x = twoSellerOrder($this);
    expect($x['soA']->isTransferred())->toBeTrue();

    $result = $this->refunds->refundSellerOrder($this->admin, $x['soA'], 'Customer asked');

    $refundCall = fakeGateway()->lastCall('refund');
    expect($refundCall['paymentIntentId'])->toBe('pi_refund')
        ->and($refundCall['amountCents'])->toBe(3000) // items + shipping of that seller order, not the whole payment
        ->and($result->amountCents)->toBe(3000)
        ->and($result->refundId)->toBe('re_fake_1')
        ->and($result->hasFailedReversals())->toBeFalse();

    $reverseCall = fakeGateway()->lastCall('reverseTransfer');
    expect($reverseCall['transferId'])->toBe($x['soA']->fresh()->stripe_transfer_id)
        ->and($reverseCall['amountCents'])->toBe(2700); // the 3000 minus the 300 fee the seller received

    $soA = $x['soA']->fresh();
    expect($soA->status)->toBe(SellerOrderStatus::REFUNDED)
        ->and($soA->refunded_cents)->toBe(3000)
        ->and($soA->stripe_refund_id)->toBe('re_fake_1')
        ->and($soA->stripe_reversal_id)->not->toBeNull()
        ->and($soA->refunded_at)->not->toBeNull()
        ->and($this->buyer->hasAccessTo($x['design'], false))->toBeFalse()
        ->and($x['soB']->fresh()->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT)
        ->and($x['order']->fresh()->status)->toBe(OrderStatus::PARTIALLY_REFUNDED);

    Notification::assertSentTo($this->buyer, OrderRefunded::class, fn ($n) => $n->amountCents === 3000);
});

it('refunds the other seller order too, which makes the whole order refunded', function () {
    $x = twoSellerOrder($this);
    $this->refunds->refundSellerOrder($this->admin, $x['soA']);

    $this->refunds->refundSellerOrder($this->admin, $x['soB']->fresh());

    $refundCall = fakeGateway()->lastCall('refund');
    expect($refundCall['amountCents'])->toBe(7000) // 6000 + 1000 shipping
        ->and($x['order']->fresh()->status)->toBe(OrderStatus::REFUNDED)
        // the second seller was never paid out (shipment pending), so nothing to reverse for them
        ->and(fakeGateway()->callCount('reverseTransfer'))->toBe(1);
});

it('refunds a whole untouched order with one full refund and reverses every transfer', function () {
    $x = twoSellerOrder($this);
    // seller B ships, so both have been paid out
    app(ShipmentService::class)->markShipped($x['sellerB'], $x['soB'], 'UPS', '1Z');

    $result = $this->refunds->refundOrder($this->admin, $x['order']);

    expect(fakeGateway()->callCount('refund'))->toBe(1)
        ->and(fakeGateway()->lastCall('refund')['amountCents'])->toBeNull() // the entire payment
        ->and(fakeGateway()->callCount('reverseTransfer'))->toBe(2)
        ->and($result->amountCents)->toBe($x['order']->total_cents)
        ->and($result->sellerOrders)->toHaveCount(2)
        ->and($x['order']->fresh()->status)->toBe(OrderStatus::REFUNDED)
        ->and($x['order']->sellerOrders()->pluck('status')->unique()->all())->toBe([SellerOrderStatus::REFUNDED])
        ->and($this->buyer->hasAccessTo($x['design'], false))->toBeFalse();
});

it('refunds only what is left after a partial refund', function () {
    $x = twoSellerOrder($this);
    $this->refunds->refundSellerOrder($this->admin, $x['soA']);

    $this->refunds->refundOrder($this->admin, $x['order']->fresh());

    expect(fakeGateway()->lastCall('refund')['amountCents'])->toBe(7000)
        ->and($x['order']->fresh()->status)->toBe(OrderStatus::REFUNDED);
});

it('cannot refund twice, an unpaid order, or without being an admin', function () {
    $x = twoSellerOrder($this);
    $this->refunds->refundOrder($this->admin, $x['order']);

    expect(fn () => $this->refunds->refundOrder($this->admin, $x['order']->fresh()))->toThrow(InvalidOrderStateException::class)
        ->and(fn () => $this->refunds->refundSellerOrder($this->admin, $x['soA']->fresh()))->toThrow(InvalidOrderStateException::class);

    $pending = pendingOrder(User::factory()->create(), [paidDesign()]);
    expect(fn () => $this->refunds->refundOrder($this->admin, $pending))->toThrow(InvalidOrderStateException::class);

    $y = twoSellerOrder($this);
    foreach ([$this->buyer, $y['sellerA'], onboardedSeller()] as $nobody) {
        expect(fn () => $this->refunds->refundOrder($nobody, $y['order']))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->refunds->refundSellerOrder($nobody, $y['soA']))->toThrow(AuthorizationException::class);
    }
    expect($y['order']->fresh()->status)->toBe(OrderStatus::PAID);
});

it('leaves everything untouched when Stripe refuses the refund', function () {
    $x = twoSellerOrder($this);
    fakeGateway()->failOn('refund');

    expect(fn () => $this->refunds->refundSellerOrder($this->admin, $x['soA']))
        ->toThrow(App\Services\Payments\Exceptions\PaymentGatewayException::class);

    expect($x['soA']->fresh()->status)->toBe(SellerOrderStatus::COMPLETED)
        ->and($x['order']->fresh()->status)->toBe(OrderStatus::PAID)
        ->and($this->buyer->hasAccessTo($x['design'], false))->toBeTrue()
        ->and(fakeGateway()->callCount('reverseTransfer'))->toBe(0);
});

it('still refunds the buyer when the payout cannot be reversed, and lets the admin retry', function () {
    $x = twoSellerOrder($this);
    fakeGateway()->failOn('reverseTransfer');

    $result = $this->refunds->refundSellerOrder($this->admin, $x['soA']);

    expect($result->hasFailedReversals())->toBeTrue()
        ->and($result->reversalFailed[0]->is($x['soA']))->toBeTrue()
        ->and($x['soA']->fresh()->status)->toBe(SellerOrderStatus::REFUNDED)
        ->and($x['soA']->fresh()->stripe_reversal_id)->toBeNull();

    expect($this->refunds->retryTransferReversal($this->admin, $x['soA']->fresh()))->toBeTrue()
        ->and($x['soA']->fresh()->stripe_reversal_id)->not->toBeNull()
        ->and($this->refunds->retryTransferReversal($this->admin, $x['soA']->fresh()))->toBeFalse();
});

it('lets the buyer buy a refunded digital item again', function () {
    $x = twoSellerOrder($this);
    $this->refunds->refundSellerOrder($this->admin, $x['soA']);
    expect($this->buyer->hasAccessTo($x['design'], false))->toBeFalse();

    $again = pendingOrder($this->buyer, [$x['design']]);
    payOrder($again, 'pi_again', 'ch_again');

    expect($this->buyer->hasAccessTo($x['design'], false))->toBeTrue();
});

it('exposes the refund ability to admins only through the policies', function () {
    $x = twoSellerOrder($this);

    expect(Gate::forUser($this->admin)->allows('refund', $x['order']))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('refund', $x['soA']))->toBeTrue()
        ->and(Gate::forUser($this->buyer)->allows('refund', $x['order']))->toBeFalse()
        ->and(Gate::forUser($x['sellerA'])->allows('refund', $x['soA']))->toBeFalse()
        // viewing: buyer and the involved sellers, not strangers
        ->and(Gate::forUser($this->buyer)->allows('view', $x['order']))->toBeTrue()
        ->and(Gate::forUser($x['sellerA'])->allows('view', $x['order']))->toBeTrue()
        ->and(Gate::forUser(onboardedSeller())->allows('view', $x['order']))->toBeFalse()
        ->and(Gate::forUser($x['sellerA'])->allows('view', $x['soA']))->toBeTrue()
        ->and(Gate::forUser($x['sellerA'])->allows('view', $x['soB']))->toBeFalse();
});
