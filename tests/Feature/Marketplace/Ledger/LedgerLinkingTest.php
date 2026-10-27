<?php

use App\Enums\LedgerLink;
use App\Models\Order;
use App\Models\StripeBalanceTransaction;
use App\Services\Marketplace\RefundService;
use App\Services\Marketplace\ShipmentService;

/*
| R6.1 linking: charge -> order, refund -> order / seller order, transfer -> seller order, reversal -> transfer, dispute
| adjustment -> charge, a seller account's payment (py_) -> its source transfer -> seller order. Wave's subscription charges
| on the shared platform account are "other", never errors.
*/

beforeEach(function () {
    config(['marketplace.fee_bps' => 1000, 'marketplace.physical_payout_trigger' => 'shipped']);
    $this->admin = adminUser();
});

/** A charged order whose seller shipped it, so the payout (a transfer) was made. */
function transferredOrder(): array
{
    $x = chargedListingOrder();
    app(ShipmentService::class)->markShipped($x['seller'], $x['sellerOrder'], 'UPS', '1Z999', null);
    $x['sellerOrder'] = $x['sellerOrder']->fresh();
    $x['account'] = stripeAccountOf($x['seller']);

    return $x;
}

function ledgerRow(string $type, ?string $account = null, ?string $sourceId = null): StripeBalanceTransaction
{
    return StripeBalanceTransaction::query()->forAccount($account)->where('type', $type)
        ->when($sourceId, fn ($q) => $q->where('source_id', $sourceId))->firstOrFail();
}

it('links a payment to its order by the charge id', function () {
    $x = chargedListingOrder();
    syncLedger();

    $row = ledgerRow('charge');
    expect($row->link_status)->toBe(LedgerLink::LINKED)->and($row->order_id)->toBe($x['order']->id)->and($row->seller_order_id)->toBeNull();
});

it('links a payment by its payment intent when the charge id differs, and by the order marker for an order that is not paid yet', function () {
    $buyer = App\Models\User::factory()->create();
    $byIntent = paidListingOrder($buyer)['order'];
    fakeGateway()->recordCharge('ch_renamed', $byIntent->total_cents, ['payment_intent' => $byIntent->stripe_payment_intent_id]);

    $listing = sellableListing(onboardedSeller(), 5000, ['quantity' => 2, 'shipping_domestic_cents' => 700]);
    $pending = pendingOrder($buyer, [$listing], addressFor($buyer));
    fakeGateway()->recordCharge('ch_before_the_webhook', $pending->total_cents, [
        'payment_intent' => 'pi_not_known_yet',
        'metadata' => ['order_id' => (string) $pending->id, 'order_number' => $pending->number],
    ]);

    syncLedger();

    expect(ledgerRow('charge', null, 'ch_renamed')->order_id)->toBe($byIntent->id)
        ->and(ledgerRow('charge', null, 'ch_before_the_webhook')->order_id)->toBe($pending->id)
        ->and(ledgerRow('charge', null, 'ch_before_the_webhook')->link_status)->toBe(LedgerLink::LINKED);
});

it('leaves Wave subscription charges alone: other, no order, nothing to report', function () {
    fakeGateway()->recordSubscriptionCharge(1900, 'ch_wave_sub');
    fakeGateway()->recordRefund('ch_wave_sub', 1900);
    chargedListingOrder();

    syncLedger();

    $charge = ledgerRow('charge', null, 'ch_wave_sub');
    $refund = ledgerRow('refund');
    expect($charge->link_status)->toBe(LedgerLink::OTHER)->and($charge->order_id)->toBeNull()->and($charge->description)->toBe('Subscription creation')
        ->and($refund->link_status)->toBe(LedgerLink::OTHER)->and($refund->charge_id)->toBe('ch_wave_sub')
        ->and(StripeBalanceTransaction::where('link_status', LedgerLink::UNLINKED->value)->count())->toBe(0);
});

it('keeps only the order keys of an object\'s metadata: Wave\'s billing data is none of our business', function () {
    fakeGateway()->recordCharge('ch_wave_meta', 1900, ['metadata' => ['user_id' => '12', 'email' => 'member@example.test', 'plan' => 'pro']]);
    $x = chargedListingOrder();

    syncLedger();

    expect(ledgerRow('charge', null, 'ch_wave_meta')->source_metadata)->toBeNull()
        ->and(ledgerRow('charge', null, $x['order']->stripe_charge_id)->source_metadata)->toBe(['order_id' => (string) $x['order']->id, 'order_number' => $x['order']->number]);
});

it('does not trust an order marker that names no matching order: such a payment is unlinked', function () {
    $order = Order::factory()->create();
    fakeGateway()->recordCharge('ch_no_such_order', 4000, ['metadata' => ['order_id' => '99999', 'order_number' => 'SDL-NOPE00']]);
    // the right number but another order's id
    fakeGateway()->recordCharge('ch_mixed_up', 4000, ['metadata' => ['order_id' => (string) ($order->id + 1000), 'order_number' => $order->number]]);

    syncLedger();

    foreach (['ch_no_such_order', 'ch_mixed_up'] as $charge) {
        $row = ledgerRow('charge', null, $charge);
        expect($row->link_status)->toBe(LedgerLink::UNLINKED)->and($row->order_id)->toBeNull();
    }
});

it('links a refund to the order of its charge, and to the seller order whose refund it is', function () {
    $x = chargedListingOrder();
    app(RefundService::class)->refundSellerOrder($this->admin, $x['sellerOrder'], 'Changed mind');

    syncLedger();

    $refund = ledgerRow('refund');
    expect($refund->amount_cents)->toBe(-6900)->and($refund->fee_cents)->toBe(0)
        ->and($refund->link_status)->toBe(LedgerLink::LINKED)
        ->and($refund->order_id)->toBe($x['order']->id)
        ->and($refund->seller_order_id)->toBe($x['sellerOrder']->id)
        ->and($refund->refund_id)->toBe($x['sellerOrder']->fresh()->stripe_refund_id)
        ->and($refund->charge_id)->toBe($x['order']->stripe_charge_id)
        ->and($refund->source_metadata['order_number'])->toBe($x['order']->number);
});

it('links a refund shared by several seller orders to the order only', function () {
    $buyer = App\Models\User::factory()->create();
    $sellerA = onboardedSeller();
    $sellerB = onboardedSeller();
    $order = pendingOrder($buyer, [
        sellableListing($sellerA, 4000, ['quantity' => 2, 'shipping_domestic_cents' => 500]),
        sellableListing($sellerB, 3000, ['quantity' => 2, 'shipping_domestic_cents' => 500]),
    ], addressFor($buyer));
    payOrder($order, 'pi_two', 'ch_two');
    fakeGateway()->recordOrderCharge($order->fresh());

    app(RefundService::class)->refundOrder($this->admin, $order->fresh());
    syncLedger();

    $refund = ledgerRow('refund');
    expect($refund->amount_cents)->toBe(-$order->fresh()->total_cents)
        ->and($refund->order_id)->toBe($order->id)->and($refund->seller_order_id)->toBeNull()->and($refund->link_status)->toBe(LedgerLink::LINKED);
});

it('links the transfer to its seller order, and the payment on the seller account to the same seller order', function () {
    $x = transferredOrder();
    $transfer = $x['sellerOrder']->stripe_transfer_id;

    syncLedger();
    syncLedger($x['account']);

    $out = ledgerRow('transfer');
    $in = ledgerRow('payment', $x['account']);
    expect($out->amount_cents)->toBe(-6300)->and($out->transfer_id)->toBe($transfer)->and($out->counterparty)->toBe($x['account'])
        ->and($out->link_status)->toBe(LedgerLink::LINKED)
        ->and($out->seller_order_id)->toBe($x['sellerOrder']->id)->and($out->order_id)->toBe($x['order']->id)->and($out->seller_id)->toBe($x['seller']->id)
        ->and($in->amount_cents)->toBe(6300)->and($in->transfer_id)->toBe($transfer)->and($in->source_type)->toBe('charge')
        ->and($in->link_status)->toBe(LedgerLink::LINKED)
        ->and($in->seller_order_id)->toBe($x['sellerOrder']->id)->and($in->order_id)->toBe($x['order']->id)->and($in->seller_id)->toBe($x['seller']->id);
});

it('links a transfer reversal to its transfer, and the money taken back on the seller account too', function () {
    $x = transferredOrder();
    app(RefundService::class)->refundSellerOrder($this->admin, $x['sellerOrder'], 'Damaged in transit');
    $reversal = $x['sellerOrder']->fresh()->stripe_reversal_id;

    syncLedger();
    syncLedger($x['account']);

    $platform = ledgerRow('transfer_refund');
    $seller = ledgerRow('payment_refund', $x['account']);
    expect($platform->amount_cents)->toBe(6300)->and($platform->reversal_id)->toBe($reversal)->and($platform->transfer_id)->toBe($x['sellerOrder']->stripe_transfer_id)
        ->and($platform->seller_order_id)->toBe($x['sellerOrder']->id)->and($platform->link_status)->toBe(LedgerLink::LINKED)
        ->and($seller->amount_cents)->toBe(-6300)->and($seller->reversal_id)->toBe($reversal)
        // the seller's refund knows only the py_ payment: its transfer comes through the mirrored payment
        ->and($seller->transfer_id)->toBeNull()->and($seller->charge_id)->toBe(ledgerRow('payment', $x['account'])->source_id)
        ->and($seller->seller_order_id)->toBe($x['sellerOrder']->id)->and($seller->link_status)->toBe(LedgerLink::LINKED);
});

it('links a dispute adjustment to the order of the disputed charge, not those of Wave charges', function () {
    $x = chargedListingOrder();
    fakeGateway()->recordSubscriptionCharge(1900, 'ch_wave_disputed');
    $dispute = fakeGateway()->recordDispute($x['order']->stripe_charge_id, null, ['id' => 'du_order']);
    fakeGateway()->recordDispute('ch_wave_disputed', null, ['id' => 'du_wave']);

    syncLedger();

    $adjustment = ledgerRow('adjustment', null, 'du_order');
    expect($adjustment->amount_cents)->toBe(-6900)->and($adjustment->fee_cents)->toBe(1500)->and($adjustment->net_cents)->toBe(-8400)
        ->and($adjustment->reporting_category)->toBe('dispute')->and($adjustment->dispute_id)->toBe('du_order')
        ->and($adjustment->charge_id)->toBe($x['order']->stripe_charge_id)
        ->and($adjustment->link_status)->toBe(LedgerLink::LINKED)->and($adjustment->order_id)->toBe($x['order']->id);
    expect(ledgerRow('adjustment', null, 'du_wave')->link_status)->toBe(LedgerLink::OTHER)
        ->and(ledgerRow('adjustment', null, 'du_wave')->order_id)->toBeNull();

    // A won dispute returns the money.
    fakeGateway()->closeDispute('du_order', true);
    syncLedger();
    $reversal = StripeBalanceTransaction::where('reporting_category', 'dispute_reversal')->firstOrFail();
    expect($reversal->amount_cents)->toBe(6900)->and($reversal->order_id)->toBe($x['order']->id);
});

it('files payouts, Stripe fees and the like as other', function () {
    $seller = onboardedSeller();
    fakeGateway()->recordPayout(stripeAccountOf($seller), 5000, ['id' => 'po_x']);
    fakeGateway()->recordPayout(null, 7000, ['id' => 'po_platform']);

    syncLedger(stripeAccountOf($seller));
    syncLedger();

    $payout = ledgerRow('payout', stripeAccountOf($seller));
    expect($payout->link_status)->toBe(LedgerLink::OTHER)->and($payout->payout_id)->toBe('po_x')->and($payout->amount_cents)->toBe(-5000)
        ->and($payout->seller_id)->toBe($seller->id)
        ->and(ledgerRow('payout')->link_status)->toBe(LedgerLink::OTHER);
});

it('marks a transfer no seller order claims as unlinked, and links it once the site records its side', function () {
    $seller = onboardedSeller();
    $account = stripeAccountOf($seller);
    fakeGateway()->recordTransfer('tr_late_record', $account, 2500, null, 'SDL-LATE00', ['seller_order_id' => '4242']);

    syncLedger();
    syncLedger($account);

    $out = ledgerRow('transfer');
    $in = ledgerRow('payment', $account);
    expect($out->link_status)->toBe(LedgerLink::UNLINKED)->and($out->seller_order_id)->toBeNull()->and($out->source_metadata['seller_order_id'])->toBe('4242')
        ->and($in->link_status)->toBe(LedgerLink::UNLINKED);

    // The site writes the transfer id after Stripe booked it (the sync was faster than the request): the next sync links.
    $order = Order::factory()->paid()->create();
    $sellerOrder = $order->sellerOrders()->first() ?? App\Models\SellerOrder::factory()->for($order)->paid()->create(['seller_id' => $seller->id]);
    $sellerOrder->forceFill(['seller_id' => $seller->id, 'stripe_transfer_id' => 'tr_late_record', 'transferred_at' => now(), 'payout_cents' => 2500])->save();
    $this->travel(10)->minutes();

    syncLedger();
    syncLedger($account);

    expect($out->fresh()->link_status)->toBe(LedgerLink::LINKED)->and($out->fresh()->seller_order_id)->toBe($sellerOrder->id)
        ->and($in->fresh()->link_status)->toBe(LedgerLink::LINKED)->and($in->fresh()->order_id)->toBe($order->id);
});
