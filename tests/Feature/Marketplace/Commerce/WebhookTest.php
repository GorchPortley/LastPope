<?php

use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Models\Order;
use App\Models\StripeEvent;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

const MARKETPLACE_SECRET = 'whsec_marketplace_test';
const CONNECT_SECRET = 'whsec_connect_test';

beforeEach(function () {
    config([
        'marketplace.stripe.marketplace_webhook_secret' => MARKETPLACE_SECRET,
        'marketplace.stripe.connect_webhook_secret' => CONNECT_SECRET,
        'marketplace.physical_payout_trigger' => 'shipped',
    ]);
    $this->buyer = User::factory()->create();
});

function marketplaceHook(object $test, array $event, ?string $secret = MARKETPLACE_SECRET, ?string $signature = null)
{
    return postStripeEvent($test, 'stripe.marketplace.webhook', $event, $secret, $signature);
}

// --- Signatures ---------------------------------------------------------------------------------

it('rejects a webhook without or with a wrong signature and does not touch the order', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 1000)]);
    $event = stripeEventPayload('checkout.session.completed', checkoutSessionObject($order));

    marketplaceHook($this, $event, signature: 'garbage')->assertStatus(400);
    marketplaceHook($this, $event, secret: 'whsec_somebody_else')->assertStatus(400);
    $this->call('POST', route('stripe.marketplace.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($event))->assertStatus(400);

    // a tampered body with the signature of the original
    $signature = Stripe\WebhookSignature::generateSignatureHeader(json_encode($event), MARKETPLACE_SECRET);
    $event['data']['object']['id'] = 'cs_tampered';
    marketplaceHook($this, $event, signature: $signature)->assertStatus(400);

    // an old signature (replay)
    $payload = json_encode(stripeEventPayload('checkout.session.completed', checkoutSessionObject($order)));
    $old = Stripe\WebhookSignature::generateSignatureHeader($payload, MARKETPLACE_SECRET, time() - 3600);
    $this->call('POST', route('stripe.marketplace.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $old], $payload)->assertStatus(400);

    expect($order->fresh()->status)->toBe(OrderStatus::PENDING)
        ->and(StripeEvent::count())->toBe(0);
});

it('does not accept the connect secret on the marketplace endpoint and vice versa', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 1000)]);

    marketplaceHook($this, stripeEventPayload('checkout.session.completed', checkoutSessionObject($order)), secret: CONNECT_SECRET)->assertStatus(400);

    $seller = onboardedSeller();
    $event = stripeEventPayload('account.updated', ['id' => $seller->sellerProfile->stripe_account_id, 'object' => 'account']);
    postStripeEvent($this, 'stripe.connect.webhook', $event, MARKETPLACE_SECRET)->assertStatus(400);
});

it('answers 503 and does nothing while the signing secret is not configured', function () {
    config(['marketplace.stripe.marketplace_webhook_secret' => null]);
    $order = pendingOrder($this->buyer, [paidDesign(cents: 1000)]);

    marketplaceHook($this, stripeEventPayload('checkout.session.completed', checkoutSessionObject($order)), secret: '')->assertStatus(503);

    expect($order->fresh()->status)->toBe(OrderStatus::PENDING);
});

it('needs no csrf token and no session', function () {
    $this->assertFalse(in_array('web', Route::getRoutes()->getByName('stripe.marketplace.webhook')->gatherMiddleware(), true));
    marketplaceHook($this, stripeEventPayload('some.unknown.event', ['id' => 'x']))->assertOk();
});

// --- Payments -----------------------------------------------------------------------------------

it('fulfils the order on a paid checkout.session.completed', function () {
    Notification::fake();
    $design = paidDesign(cents: 1000);
    $order = pendingOrder($this->buyer, [$design]);
    $event = stripeEventPayload('checkout.session.completed', checkoutSessionObject($order, 'paid', 'pi_hook_1'));

    marketplaceHook($this, $event)->assertOk()->assertJson(['status' => 'handled']);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::COMPLETED)
        ->and($order->stripe_payment_intent_id)->toBe('pi_hook_1')
        ->and($order->stripe_charge_id)->toBe('ch_for_pi_hook_1')
        ->and($this->buyer->hasAccessTo($design, false))->toBeTrue()
        ->and(fakeGateway()->lastCall('createTransfer')['sourceCharge'])->toBe('ch_for_pi_hook_1')
        ->and(StripeEvent::find($event['id'])->isProcessed())->toBeTrue();
});

it('ignores a checkout.session.completed that is not paid yet and fulfils on async_payment_succeeded', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 1000)]);

    marketplaceHook($this, stripeEventPayload('checkout.session.completed', checkoutSessionObject($order, 'unpaid')))
        ->assertOk()->assertJson(['status' => 'ignored']);
    expect($order->fresh()->status)->toBe(OrderStatus::PENDING);

    marketplaceHook($this, stripeEventPayload('checkout.session.async_payment_succeeded', checkoutSessionObject($order, 'paid')))
        ->assertOk()->assertJson(['status' => 'handled']);
    expect($order->fresh()->status)->toBe(OrderStatus::COMPLETED);
});

it('records the tax and total Stripe really charged', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 1000)]);
    $session = checkoutSessionObject($order, extra: ['amount_total' => 1190, 'total_details' => ['amount_tax' => 190]]);

    marketplaceHook($this, stripeEventPayload('checkout.session.completed', $session))->assertOk();

    expect($order->fresh()->tax_cents)->toBe(190)->and($order->fresh()->total_cents)->toBe(1190);
});

it('is a no-op for a duplicate event id', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 1000)]);
    $event = stripeEventPayload('checkout.session.completed', checkoutSessionObject($order));

    marketplaceHook($this, $event)->assertOk()->assertJson(['status' => 'handled']);
    marketplaceHook($this, $event)->assertOk()->assertJson(['status' => 'duplicate']);

    expect(fakeGateway()->callCount('findChargeId'))->toBe(1)
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(1)
        ->and(StripeEvent::where('id', $event['id'])->count())->toBe(1);
});

it('is also safe when Stripe sends completed and async_succeeded as two different events', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 1000)]);

    marketplaceHook($this, stripeEventPayload('checkout.session.completed', checkoutSessionObject($order)))->assertOk();
    marketplaceHook($this, stripeEventPayload('checkout.session.async_payment_succeeded', checkoutSessionObject($order)))->assertOk();

    expect(fakeGateway()->callCount('createTransfer'))->toBe(1)
        ->and($this->buyer->entitlements()->count())->toBe(1);
});

it('lets Stripe retry when processing fails', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 1000)]);
    $event = stripeEventPayload('checkout.session.completed', checkoutSessionObject($order));
    fakeGateway()->failOn('findChargeId');

    $this->withoutExceptionHandling();
    expect(fn () => marketplaceHook($this, $event))->toThrow(App\Services\Payments\Exceptions\PaymentGatewayException::class);

    expect(StripeEvent::find($event['id'])?->isProcessed())->toBeFalse()
        ->and($order->fresh()->status)->toBe(OrderStatus::PENDING);

    // the redelivery succeeds
    marketplaceHook($this, $event)->assertOk()->assertJson(['status' => 'handled']);
    expect($order->fresh()->status)->toBe(OrderStatus::COMPLETED);
});

it('ignores sessions that are not marketplace orders (e.g. Wave subscriptions)', function () {
    $event = stripeEventPayload('checkout.session.completed', [
        'id' => 'cs_subscription_1', 'object' => 'checkout.session', 'payment_status' => 'paid', 'payment_intent' => null, 'metadata' => [],
    ]);

    marketplaceHook($this, $event)->assertOk()->assertJson(['status' => 'ignored']);
    expect(fakeGateway()->callCount('findChargeId'))->toBe(0);
});

it('cancels the pending order when the checkout session expires, but never a paid one', function () {
    $pending = pendingOrder($this->buyer, [sellableListing()], addressFor($this->buyer));
    $other = User::factory()->create();
    $paid = pendingOrder($other, [paidDesign(cents: 1000)]);
    payOrder($paid);

    marketplaceHook($this, stripeEventPayload('checkout.session.expired', checkoutSessionObject($pending, 'unpaid')))->assertOk();
    marketplaceHook($this, stripeEventPayload('checkout.session.expired', checkoutSessionObject($paid, 'unpaid')))->assertOk();

    expect($pending->fresh()->status)->toBe(OrderStatus::CANCELLED)
        ->and($pending->sellerOrders()->pluck('status')->unique()->all())->toBe([SellerOrderStatus::CANCELLED])
        ->and($paid->fresh()->status)->toBe(OrderStatus::COMPLETED);
});

// --- Refunds ------------------------------------------------------------------------------------

it('syncs a full refund made in the Stripe dashboard: seller orders refunded, access revoked, payout reversed', function () {
    Notification::fake();
    $design = paidDesign(cents: 2000);
    $order = pendingOrder($this->buyer, [$design]);
    payOrder($order, 'pi_dash', 'ch_dash');
    $charge = ['id' => 'ch_dash', 'object' => 'charge', 'payment_intent' => 'pi_dash', 'amount' => 2000, 'amount_refunded' => 2000, 'refunded' => true];

    marketplaceHook($this, stripeEventPayload('charge.refunded', $charge))->assertOk()->assertJson(['status' => 'handled']);

    $order->refresh();
    $sellerOrder = $order->sellerOrders()->first();
    expect($order->status)->toBe(OrderStatus::REFUNDED)
        ->and($sellerOrder->status)->toBe(SellerOrderStatus::REFUNDED)
        ->and($sellerOrder->refunded_cents)->toBe(2000)
        ->and($sellerOrder->stripe_reversal_id)->not->toBeNull()
        ->and($this->buyer->hasAccessTo($design, false))->toBeFalse()
        ->and(fakeGateway()->callCount('reverseTransfer'))->toBe(1)
        ->and(fakeGateway()->callCount('refund'))->toBe(0);
});

it('attributes a partial dashboard refund to the seller order with that exact amount', function () {
    $designA = paidDesign(cents: 2000);
    $designB = paidDesign(cents: 3000);
    $order = pendingOrder($this->buyer, [$designA, $designB]);
    payOrder($order, 'pi_part', 'ch_part');

    $charge = ['id' => 'ch_part', 'object' => 'charge', 'payment_intent' => 'pi_part', 'amount' => 5000, 'amount_refunded' => 3000];
    marketplaceHook($this, stripeEventPayload('charge.refunded', $charge))->assertOk();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::PARTIALLY_REFUNDED)
        ->and($order->sellerOrders->where('status', SellerOrderStatus::REFUNDED)->pluck('subtotal_cents')->all())->toBe([3000])
        ->and($this->buyer->hasAccessTo($designB, false))->toBeFalse()
        ->and($this->buyer->hasAccessTo($designA, false))->toBeTrue();
});

it('flags an unattributable partial refund without touching seller orders', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 2000), paidDesign(cents: 3000)]);
    payOrder($order, 'pi_odd', 'ch_odd');

    marketplaceHook($this, stripeEventPayload('charge.refunded', ['id' => 'ch_odd', 'object' => 'charge', 'payment_intent' => 'pi_odd', 'amount' => 5000, 'amount_refunded' => 700]))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::PARTIALLY_REFUNDED)
        ->and($order->sellerOrders()->where('status', SellerOrderStatus::REFUNDED->value)->count())->toBe(0);
});

it('ignores refunds of unknown charges and does nothing for refunds we already recorded', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 2000)]);
    payOrder($order, 'pi_known', 'ch_known');
    $admin = User::factory()->admin()->create();
    app(App\Services\Marketplace\RefundService::class)->refundOrder($admin, $order->fresh());
    $before = fakeGateway()->callCount('reverseTransfer');

    marketplaceHook($this, stripeEventPayload('charge.refunded', ['id' => 'ch_nope', 'object' => 'charge', 'payment_intent' => 'pi_nope', 'amount' => 100, 'amount_refunded' => 100]))
        ->assertOk()->assertJson(['status' => 'ignored']);
    marketplaceHook($this, stripeEventPayload('charge.refunded', ['id' => 'ch_known', 'object' => 'charge', 'payment_intent' => 'pi_known', 'amount' => 2000, 'amount_refunded' => 2000]))
        ->assertOk();

    expect(fakeGateway()->callCount('reverseTransfer'))->toBe($before)
        ->and($order->fresh()->status)->toBe(OrderStatus::REFUNDED);
});

// --- Connect ------------------------------------------------------------------------------------

it('updates the seller flags on account.updated', function () {
    $seller = User::factory()->seller(['stripe_account_id' => 'acct_flags_1'])->create();
    expect($seller->canReceivePayouts())->toBeFalse();

    $event = stripeEventPayload('account.updated', [
        'id' => 'acct_flags_1', 'object' => 'account',
        'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true,
    ], ['account' => 'acct_flags_1']);
    postStripeEvent($this, 'stripe.connect.webhook', $event, CONNECT_SECRET)->assertOk()->assertJson(['status' => 'handled']);

    $profile = $seller->sellerProfile()->first();
    expect($profile->charges_enabled)->toBeTrue()
        ->and($profile->payouts_enabled)->toBeTrue()
        ->and($profile->details_submitted)->toBeTrue()
        ->and($seller->fresh()->canReceivePayouts())->toBeTrue();

    // Stripe later restricts the account again
    $restricted = stripeEventPayload('account.updated', [
        'id' => 'acct_flags_1', 'object' => 'account',
        'charges_enabled' => true, 'payouts_enabled' => false, 'details_submitted' => true,
    ]);
    postStripeEvent($this, 'stripe.connect.webhook', $restricted, CONNECT_SECRET)->assertOk();

    expect($seller->fresh()->canReceivePayouts())->toBeFalse();
});

it('ignores account.updated for unknown accounts and de-duplicates connect events too', function () {
    $event = stripeEventPayload('account.updated', ['id' => 'acct_unknown', 'object' => 'account', 'charges_enabled' => true]);

    postStripeEvent($this, 'stripe.connect.webhook', $event, CONNECT_SECRET)->assertOk()->assertJson(['status' => 'ignored']);
    postStripeEvent($this, 'stripe.connect.webhook', $event, CONNECT_SECRET)->assertOk()->assertJson(['status' => 'duplicate']);
});

it('leaves Wave\'s subscription webhook route alone', function () {
    expect(Route::has('stripe.marketplace.webhook'))->toBeTrue()
        ->and(collect(Route::getRoutes()->getRoutes())->contains(fn ($r) => $r->uri() === 'webhook/stripe'))->toBeTrue();
});
