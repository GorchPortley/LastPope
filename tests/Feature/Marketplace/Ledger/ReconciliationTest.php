<?php

use App\Enums\DiscrepancyKind;
use App\Models\Order;
use App\Models\StripeBalanceTransaction;
use App\Models\StripeDiscrepancy;
use App\Models\User;
use App\Notifications\StripeDifferencesFound;
use App\Services\Marketplace\IssueService;
use App\Services\Marketplace\Ledger\Reconciler;
use App\Services\Marketplace\RefundService;
use App\Services\Marketplace\ShipmentService;
use Illuminate\Support\Facades\Notification;

/*
| R6.3: the daily reconciliation of the site's records with the Stripe ledger. Every check, the dedup / auto-resolve rules, the
| tax rule for refunds, the timing guards and the mail to the admins. Fixtures come from the fake gateway's ledger, which books
| what the site asks for (transfers, reversals, refunds) the way Stripe does; tests then break it on purpose.
*/

beforeEach(function () {
    Notification::fake();
    config(['marketplace.fee_bps' => 1000, 'marketplace.physical_payout_trigger' => 'shipped', 'marketplace.stripe.secret_key' => 'sk_test_ledger']);
    $this->travelTo(now()->startOfSecond());
    $this->admin = adminUser();
    $this->buyer = User::factory()->create();
    // The events happen "now"; the ledger is read two hours later, when every check may look at them.
    $this->settle = function () {
        $this->travel(2)->hours();
        syncAllLedgers();
    };
    $this->reconcile = fn (bool $notify = false) => app(Reconciler::class)->run(notify: $notify);
    $this->open = fn () => StripeDiscrepancy::open()->orderBy('kind')->get()->map(fn ($d) => $d->kind->value.' '.$d->key)->all();
});

/**
 * An order of a digital design (seller A, paid out at once) and a listing (seller B, shipped and paid out), charged with $tax
 * on top: items 3000 + 6000, shipping 1000, so gross A 3000, gross B 7000, payouts 2700 and 6400.
 */
function journey(object $test, int $tax = 0, bool $shipped = true): array
{
    $designer = onboardedSeller();
    $shop = onboardedSeller();
    $order = pendingOrder($test->buyer, [
        paidDesign($designer, 3000),
        sellableListing($shop, 6000, ['shipping_domestic_cents' => 1000, 'additional_item_shipping_cents' => 0, 'quantity' => 2]),
    ], addressFor($test->buyer));
    $order = chargeAndPay($order, $tax);
    $soA = $order->sellerOrders()->where('seller_id', $designer->id)->firstOrFail();
    $soB = $order->sellerOrders()->where('seller_id', $shop->id)->firstOrFail();

    if ($shipped) {
        app(ShipmentService::class)->markShipped($shop, $soB, 'UPS', '1Z1', null);
        $soB = $soB->fresh();
    }

    return ['order' => $order->fresh(), 'soA' => $soA->fresh(), 'soB' => $soB, 'designer' => $designer, 'shop' => $shop];
}

// --- Nothing to report ----------------------------------------------------------------------------------------------

it('finds nothing when the site and Stripe agree, whatever the flow: payments, payouts, refunds, reversals, Wave charges', function () {
    $a = journey($this, tax: 500);                       // whole order refunded below, tax included
    $b = journey($this);                                 // one seller order refunded after its payout
    $c = journey($this, tax: 300);                       // refunded seller order by seller order: the tax stays
    journey($this);                                      // untouched
    fakeGateway()->recordSubscriptionCharge(1900, 'ch_wave_1');
    fakeGateway()->recordRefund('ch_wave_1', 900);

    app(RefundService::class)->refundOrder($this->admin, $a['order']);
    app(RefundService::class)->refundSellerOrderPartially($this->admin, $b['soB'], 2000, 'Scratched');
    app(RefundService::class)->refundSellerOrder($this->admin, $c['soA']);
    app(RefundService::class)->refundSellerOrder($this->admin, $c['soB']->fresh());
    fakeGateway()->recordPayout(null, 5000, ['status' => 'paid']);
    ($this->settle)();

    $result = ($this->reconcile)();

    expect($result->skipped)->toBe([])->and($result->open)->toHaveCount(0)->and($result->new)->toHaveCount(0)->and($result->autoResolved)->toBe(0)
        ->and(StripeDiscrepancy::count())->toBe(0);

    // the checks really looked: the same run finds the differences once the ledger is changed
    fakeGateway()->forgetLedgerTransaction(StripeBalanceTransaction::where('charge_id', $b['order']->stripe_charge_id)->where('type', 'charge')->value('id'));
    resyncLedgers();
    StripeBalanceTransaction::where('charge_id', $b['order']->stripe_charge_id)->where('type', 'charge')->delete();
    expect(($this->reconcile)()->open)->not->toBeEmpty();
});

it('does nothing until the ledger has been synced, and says so', function () {
    $x = journey($this);
    $this->travel(2)->hours();

    $result = ($this->reconcile)();

    expect($result->skipped)->toHaveCount(1)->and($result->skipped[0])->toContain('stripe:ledger-sync')
        ->and(StripeDiscrepancy::count())->toBe(0);
});

// --- 1. charges -------------------------------------------------------------------------------------------------------

it('reports a paid order whose charge is not in Stripe, and a charge for another amount', function () {
    $missing = journey($this);
    $wrong = journey($this, tax: 250);
    $platform = StripeBalanceTransaction::query();
    ($this->settle)();
    fakeGateway()->forgetLedgerTransaction(StripeBalanceTransaction::where('charge_id', $missing['order']->stripe_charge_id)->where('type', 'charge')->value('id'));
    fakeGateway()->alterLedgerTransaction(StripeBalanceTransaction::where('charge_id', $wrong['order']->stripe_charge_id)->where('type', 'charge')->value('id'), ['amount' => 8000, 'net' => 7700]);
    resyncLedgers();
    StripeBalanceTransaction::where('charge_id', $missing['order']->stripe_charge_id)->where('type', 'charge')->delete();

    ($this->reconcile)();

    $gone = StripeDiscrepancy::where('kind', DiscrepancyKind::CHARGE_MISSING->value)->firstOrFail();
    $amount = StripeDiscrepancy::where('kind', DiscrepancyKind::CHARGE_AMOUNT->value)->firstOrFail();
    expect(StripeDiscrepancy::count())->toBe(2)
        ->and($gone->key)->toBe('order:'.$missing['order']->id)->and($gone->order_id)->toBe($missing['order']->id)
        ->and($gone->expected_cents)->toBe(10000)->and($gone->actual_cents)->toBeNull()->and($gone->stripe_id)->toBe($missing['order']->stripe_charge_id)
        ->and($gone->details)->toContain($missing['order']->number)->toContain('$100.00')
        ->and($amount->order_id)->toBe($wrong['order']->id)->and($amount->expected_cents)->toBe(10250)->and($amount->actual_cents)->toBe(8000)
        ->and($amount->details)->toContain('tax included');
});

it('leaves unpaid orders, orders from the other Stripe mode and orders from before the ledger alone', function () {
    $old = journey($this);                                               // paid before the first transaction the ledger knows
    Order::whereKey($old['order']->id)->update(['paid_at' => now()->subYears(2)]);
    $testMode = journey($this);                                          // a cs_test_ order while the keys are live
    Order::whereKey($testMode['order']->id)->update(['stripe_checkout_session_id' => 'cs_test_abc']);
    $stranger = User::factory()->create();
    pendingOrder($stranger, [sellableListing(onboardedSeller(), 3000, ['quantity' => 2])], addressFor($stranger));   // not paid
    config(['marketplace.stripe.secret_key' => 'sk_live_ledger']);
    fakeGateway()->forgetLedgerTransaction(StripeBalanceTransaction::query()->max('id') ?? 'x');
    fakeGateway()->resetLedger();
    ($this->settle)();

    $result = ($this->reconcile)();

    expect($result->open)->toHaveCount(0);
});

// --- 2. refunds and the tax rule ----------------------------------------------------------------------------------------

it('compares refunds like with like: a whole-order refund also refunds the tax, which refunded_cents does not count', function () {
    $whole = journey($this, tax: 500);
    $bySeller = journey($this, tax: 500);
    app(RefundService::class)->refundOrder($this->admin, $whole['order']);
    app(RefundService::class)->refundSellerOrder($this->admin, $bySeller['soA']);
    app(RefundService::class)->refundSellerOrder($this->admin, $bySeller['soB']->fresh());
    ($this->settle)();

    // what Stripe refunded: 10500 for the whole order (tax included), 10000 for the order refunded part by part
    $refunded = fn (Order $order) => -StripeBalanceTransaction::where('charge_id', $order->stripe_charge_id)->where('type', 'refund')->sum('amount_cents');
    expect($refunded($whole['order']))->toBe(10500)->and($refunded($bySeller['order']))->toBe(10000)
        ->and($whole['soA']->fresh()->refunded_cents + $whole['soB']->fresh()->refunded_cents)->toBe(10000);

    expect(($this->reconcile)()->open)->toHaveCount(0);
});

it('reports a refund total that differs: Stripe refunded more, less, or the site missed a refund', function () {
    $short = journey($this);
    $extra = journey($this, tax: 400);
    $missed = journey($this);
    app(RefundService::class)->refundSellerOrder($this->admin, $short['soA']);
    app(RefundService::class)->refundSellerOrder($this->admin, $extra['soA']);
    // Stripe-side only: a refund made in the dashboard the site never heard of
    fakeGateway()->recordRefund($missed['order']->stripe_charge_id, 1500);
    ($this->settle)();
    // the refund of $short is cut by 500 in the ledger, the one of $extra includes tax although a seller order is still open
    $shortTxn = StripeBalanceTransaction::where('charge_id', $short['order']->stripe_charge_id)->where('type', 'refund')->firstOrFail();
    fakeGateway()->alterLedgerTransaction($shortTxn->id, ['amount' => -2500, 'net' => -2500]);
    $extraTxn = StripeBalanceTransaction::where('charge_id', $extra['order']->stripe_charge_id)->where('type', 'refund')->firstOrFail();
    fakeGateway()->alterLedgerTransaction($extraTxn->id, ['amount' => -3400, 'net' => -3400]);
    resyncLedgers();

    ($this->reconcile)();

    $rows = StripeDiscrepancy::where('kind', DiscrepancyKind::REFUND_TOTAL->value)->get()->keyBy('order_id');
    expect($rows)->toHaveCount(3)
        ->and($rows[$short['order']->id]->expected_cents)->toBe(3000)->and($rows[$short['order']->id]->actual_cents)->toBe(2500)
        ->and($rows[$short['order']->id]->key)->toBe('charge:'.$short['order']->stripe_charge_id)
        // a seller order is still open, so the tax part is not expected: 3000 recorded, 3400 refunded
        ->and($rows[$extra['order']->id]->expected_cents)->toBe(3000)->and($rows[$extra['order']->id]->actual_cents)->toBe(3400)
        ->and($rows[$missed['order']->id]->expected_cents)->toBe(0)->and($rows[$missed['order']->id]->actual_cents)->toBe(1500)
        ->and($rows[$missed['order']->id]->details)->toContain('$15.00');
});

it('waits for a refund that is still settling', function () {
    $x = journey($this);
    ($this->settle)();
    app(RefundService::class)->refundSellerOrder($this->admin, $x['soA']);
    $this->travel(5)->minutes();
    resyncLedgers();
    fakeGateway()->alterLedgerTransaction(StripeBalanceTransaction::where('type', 'refund')->value('id'), ['amount' => -100, 'net' => -100]);
    resyncLedgers();

    expect(($this->reconcile)()->open)->toHaveCount(0);     // five minutes old: inside the grace period

    $this->travel(2)->hours();
    resyncLedgers();

    expect(($this->reconcile)()->open)->toHaveCount(1);
});

// --- 3. transfers and reversals -----------------------------------------------------------------------------------------

it('reports a transferred seller order whose transfer is missing, for another amount, or for another account', function () {
    $missing = journey($this);
    $amount = journey($this);
    $account = journey($this);
    ($this->settle)();
    $txn = fn (array $x) => StripeBalanceTransaction::where('type', 'transfer')->where('transfer_id', $x['soB']->stripe_transfer_id)->value('id');
    fakeGateway()->forgetLedgerTransaction($txn($missing));
    fakeGateway()->alterLedgerTransaction($txn($amount), ['amount' => -6000, 'net' => -6000]);
    fakeGateway()->alterLedgerObject($account['soB']->stripe_transfer_id, ['destination' => 'acct_somebody_else']);
    resyncLedgers();
    StripeBalanceTransaction::where('id', $txn($missing))->delete();

    ($this->reconcile)();

    $byKind = StripeDiscrepancy::get()->groupBy(fn ($d) => $d->kind->value);
    expect($byKind->keys()->sort()->values()->all())->toContain('transfer_missing', 'transfer_amount', 'transfer_account')
        ->and($byKind['transfer_missing'][0]->seller_order_id)->toBe($missing['soB']->id)
        ->and($byKind['transfer_missing'][0]->expected_cents)->toBe(6400)
        ->and($byKind['transfer_missing'][0]->key)->toBe('seller_order:'.$missing['soB']->id)
        ->and($byKind['transfer_amount'][0]->seller_order_id)->toBe($amount['soB']->id)
        ->and($byKind['transfer_amount'][0]->expected_cents)->toBe(6400)->and($byKind['transfer_amount'][0]->actual_cents)->toBe(6000)
        ->and($byKind['transfer_account'][0]->seller_order_id)->toBe($account['soB']->id)
        ->and($byKind['transfer_account'][0]->account)->toBe('acct_somebody_else')
        ->and($byKind['transfer_account'][0]->details)->toContain(stripeAccountOf($account['shop']));
});

it('reports a transfer that never reached the seller\'s own ledger, once that ledger has been read past it', function () {
    $x = journey($this);
    ($this->settle)();
    $account = stripeAccountOf($x['shop']);
    fakeGateway()->forgetLedgerTransaction(StripeBalanceTransaction::forAccount($account)->where('type', 'payment')->value('id'));
    StripeBalanceTransaction::forAccount($account)->where('type', 'payment')->delete();

    // the seller account was read before the transfer: not known yet, so not reported
    App\Models\StripeSyncCursor::forAccount($account)->forceFill(['synced_through' => $x['soB']->transferred_at->copy()->subMinute()])->save();
    expect(($this->reconcile)()->open->pluck('kind')->map->value->all())->not->toContain('transfer_not_received');

    App\Models\StripeSyncCursor::forAccount($account)->forceFill(['synced_through' => now()])->save();
    $result = ($this->reconcile)();

    $row = StripeDiscrepancy::where('kind', DiscrepancyKind::TRANSFER_NOT_RECEIVED->value)->firstOrFail();
    expect($row->seller_order_id)->toBe($x['soB']->id)->and($row->account)->toBe($account)->and($row->expected_cents)->toBe(6400)->and($row->actual_cents)->toBeNull();
});

it('compares the reversals in Stripe with the amount the site took back', function () {
    $partial = journey($this);
    $lost = journey($this);
    app(RefundService::class)->refundSellerOrderPartially($this->admin, $partial['soB'], 2000, 'Scratched');
    app(RefundService::class)->refundSellerOrder($this->admin, $lost['soB']);
    ($this->settle)();
    expect(($this->reconcile)()->open)->toHaveCount(0)
        ->and($partial['soB']->fresh()->reversed_cents)->toBe(2000)->and($lost['soB']->fresh()->reversed_cents)->toBe(6400);

    // Stripe never took the full amount back
    fakeGateway()->forgetLedgerTransaction(StripeBalanceTransaction::where('type', 'transfer_refund')->where('transfer_id', $lost['soB']->stripe_transfer_id)->value('id'));
    StripeBalanceTransaction::where('type', 'transfer_refund')->where('transfer_id', $lost['soB']->stripe_transfer_id)->delete();
    resyncLedgers();
    StripeBalanceTransaction::where('type', 'transfer_refund')->where('transfer_id', $lost['soB']->stripe_transfer_id)->delete();

    ($this->reconcile)();

    $row = StripeDiscrepancy::where('kind', DiscrepancyKind::REVERSAL_TOTAL->value)->firstOrFail();
    expect(StripeDiscrepancy::count())->toBe(1)
        ->and($row->seller_order_id)->toBe($lost['soB']->id)->and($row->expected_cents)->toBe(6400)->and($row->actual_cents)->toBe(0);
});

// --- 4. transfers no seller order owns ------------------------------------------------------------------------------------

it('reports a platform transfer that no seller order owns', function () {
    $seller = onboardedSeller();
    fakeGateway()->recordTransfer('tr_by_hand', stripeAccountOf($seller), 4000, null, 'SDL-HAND00', ['seller_order_id' => '777']);
    journey($this);
    ($this->settle)();

    ($this->reconcile)();

    $row = StripeDiscrepancy::firstOrFail();
    expect(StripeDiscrepancy::count())->toBe(1)
        ->and($row->kind)->toBe(DiscrepancyKind::TRANSFER_ORPHAN)->and($row->key)->toBe('transfer:tr_by_hand')
        ->and($row->actual_cents)->toBe(4000)->and($row->account)->toBe(stripeAccountOf($seller))
        ->and($row->details)->toContain('tr_by_hand')->toContain('seller order 777');
});

// --- 5. payments of orders that are not paid here -----------------------------------------------------------------------

it('reports a payment for an order that is not paid on the site, and closes it once the webhook has been processed', function () {
    $listing = sellableListing(onboardedSeller(), 5000, ['quantity' => 2, 'shipping_domestic_cents' => 700]);
    $order = pendingOrder($this->buyer, [$listing], addressFor($this->buyer));
    fakeGateway()->recordCharge('ch_missed_hook', $order->total_cents, [
        'payment_intent' => 'pi_missed_hook', 'metadata' => ['order_id' => (string) $order->id, 'order_number' => $order->number],
    ]);
    ($this->settle)();

    ($this->reconcile)();

    $row = StripeDiscrepancy::firstOrFail();
    expect($row->kind)->toBe(DiscrepancyKind::CHARGE_UNFULFILLED)->and($row->key)->toBe('charge:ch_missed_hook')->and($row->order_id)->toBe($order->id)
        ->and($row->actual_cents)->toBe($order->total_cents)->and($row->details)->toContain($order->number)->toContain('Awaiting payment');

    // the missed webhook is replayed
    payOrder($order, 'pi_missed_hook', 'ch_missed_hook');
    $result = ($this->reconcile)();

    expect($result->open)->toHaveCount(0)->and($result->autoResolved)->toBe(1)
        ->and($row->fresh()->resolution)->toBe('auto')->and($row->fresh()->resolved_at)->not->toBeNull();
});

it('reports a payment that names an order which does not exist, but never a Wave charge', function () {
    fakeGateway()->recordCharge('ch_ghost', 2500, ['metadata' => ['order_id' => '90210', 'order_number' => 'SDL-GHOST0']]);
    fakeGateway()->recordSubscriptionCharge(1900, 'ch_wave_ok');
    journey($this);
    ($this->settle)();

    ($this->reconcile)();

    $row = StripeDiscrepancy::firstOrFail();
    expect(StripeDiscrepancy::count())->toBe(1)->and($row->kind)->toBe(DiscrepancyKind::CHARGE_UNKNOWN_ORDER)->and($row->key)->toBe('charge:ch_ghost')
        ->and($row->actual_cents)->toBe(2500)->and($row->details)->toContain('SDL-GHOST0');
});

// --- 6. failed payouts ------------------------------------------------------------------------------------------------------

it('reports a failed payout of a seller and closes it when a later payout went through', function () {
    $seller = onboardedSeller();
    $account = stripeAccountOf($seller);
    fakeGateway()->recordPayout($account, 3300, ['id' => 'po_bad', 'status' => 'failed', 'failure_code' => 'incorrect_account_holder_name', 'created' => now()->subHours(3)]);
    resyncLedgers();

    ($this->reconcile)();

    $row = StripeDiscrepancy::firstOrFail();
    expect($row->kind)->toBe(DiscrepancyKind::PAYOUT_FAILED)->and($row->key)->toBe('payout:po_bad')->and($row->account)->toBe($account)
        ->and($row->actual_cents)->toBe(3300)->and($row->details)->toContain($seller->sellerProfile->shop_name)->toContain('does not match your Stripe details');

    fakeGateway()->recordPayout($account, 3300, ['id' => 'po_good', 'status' => 'paid', 'created' => now()->addMinute()]);
    $this->travel(10)->minutes();
    resyncLedgers();
    $result = ($this->reconcile)();

    expect($result->open)->toHaveCount(0)->and($row->fresh()->resolution)->toBe('auto');
});

it('reports a failed payout of the platform too, and ignores one from long ago', function () {
    fakeGateway()->recordPayout(null, 9000, ['id' => 'po_platform_bad', 'status' => 'failed', 'created' => now()->subDays(2)]);
    fakeGateway()->recordPayout(null, 9000, ['id' => 'po_ancient', 'status' => 'failed', 'created' => now()->subDays(300)]);
    syncLedger(null, now()->subDays(400));

    ($this->reconcile)();

    expect(StripeDiscrepancy::pluck('key')->all())->toBe(['payout:po_platform_bad']);
});

// --- 7. disputes ----------------------------------------------------------------------------------------------------------------

it('reports a bank dispute the site has no case for, and closes it when the case exists', function () {
    $x = journey($this);
    $dispute = fakeGateway()->recordDispute($x['order']->stripe_charge_id, null, ['id' => 'du_missed']);
    ($this->settle)();

    ($this->reconcile)();

    $row = StripeDiscrepancy::firstOrFail();
    expect($row->kind)->toBe(DiscrepancyKind::DISPUTE_NO_CASE)->and($row->key)->toBe('dispute:du_missed')->and($row->order_id)->toBe($x['order']->id)
        ->and($row->actual_cents)->toBe(10000)->and($row->details)->toContain('du_missed');

    // the dispute webhook is replayed: a case is opened
    app(IssueService::class)->recordDispute($x['order'], 'created', $dispute);
    $result = ($this->reconcile)();

    expect($result->open)->toHaveCount(0)->and($row->fresh()->resolution)->toBe('auto');
});

// --- Dedup, auto-resolve, manual resolution -------------------------------------------------------------------------------------

it('stores a difference once, keeps when it was first seen, and closes it by itself when it is fixed', function () {
    $x = journey($this);
    ($this->settle)();
    $txn = StripeBalanceTransaction::where('charge_id', $x['order']->stripe_charge_id)->where('type', 'charge')->first();
    fakeGateway()->alterLedgerTransaction($txn->id, ['amount' => 9000, 'net' => 8700]);
    resyncLedgers();

    $first = ($this->reconcile)();
    $firstSeen = StripeDiscrepancy::firstOrFail()->first_seen_at;
    $this->travel(1)->day();
    resyncLedgers();
    $second = ($this->reconcile)();

    $row = StripeDiscrepancy::firstOrFail();
    expect(StripeDiscrepancy::count())->toBe(1)
        ->and($first->new)->toHaveCount(1)->and($second->new)->toHaveCount(0)->and($second->open)->toHaveCount(1)
        ->and($row->first_seen_at->equalTo($firstSeen))->toBeTrue()->and($row->last_seen_at->gt($firstSeen))->toBeTrue()
        ->and($row->isOpen())->toBeTrue();

    // fixed in Stripe (the amount is right again)
    fakeGateway()->alterLedgerTransaction($txn->id, ['amount' => 10000, 'net' => 9700]);
    resyncLedgers();
    $third = ($this->reconcile)();

    $row->refresh();
    expect($third->open)->toHaveCount(0)->and($third->autoResolved)->toBe(1)
        ->and($row->isOpen())->toBeFalse()->and($row->resolution)->toBe('auto')->and($row->resolved_by)->toBeNull()->and($row->note)->toContain('Fixed');

    // and broken again: the same row opens again, as a new difference
    fakeGateway()->alterLedgerTransaction($txn->id, ['amount' => 9000, 'net' => 8700]);
    resyncLedgers();
    $fourth = ($this->reconcile)();

    expect(StripeDiscrepancy::count())->toBe(1)->and($fourth->new)->toHaveCount(1)->and($row->fresh()->isOpen())->toBeTrue()->and($row->fresh()->resolution)->toBeNull();
});

it('keeps a difference an admin closed closed while it stays the same, and opens it again when it changes', function () {
    $x = journey($this);
    ($this->settle)();
    $txn = StripeBalanceTransaction::where('charge_id', $x['order']->stripe_charge_id)->where('type', 'charge')->first();
    fakeGateway()->alterLedgerTransaction($txn->id, ['amount' => 9000, 'net' => 8700]);
    resyncLedgers();
    ($this->reconcile)();
    $row = StripeDiscrepancy::firstOrFail();

    $row->forceFill(['resolved_at' => now(), 'resolved_by' => $this->admin->id, 'resolution' => 'manual', 'note' => 'Buyer paid the rest by bank transfer.'])->save();

    $unchanged = ($this->reconcile)();
    expect($unchanged->open)->toHaveCount(0)->and($unchanged->new)->toHaveCount(0)->and($row->fresh()->isOpen())->toBeFalse()->and($row->fresh()->note)->toBe('Buyer paid the rest by bank transfer.');

    // Stripe's amount changes: that is not what was closed
    fakeGateway()->alterLedgerTransaction($txn->id, ['amount' => 8000, 'net' => 7700]);
    resyncLedgers();
    $changed = ($this->reconcile)();

    $row->refresh();
    expect($changed->new)->toHaveCount(1)->and($row->isOpen())->toBeTrue()->and($row->actual_cents)->toBe(8000)->and($row->note)->toBeNull()
        ->and($row->details)->toContain('found again')->toContain($this->admin->name)->toContain('Buyer paid the rest');
});

it('does not close open differences of a check that could not run', function () {
    $x = journey($this);
    ($this->settle)();
    fakeGateway()->alterLedgerTransaction(StripeBalanceTransaction::where('type', 'charge')->value('id'), ['amount' => 5000, 'net' => 4700]);
    resyncLedgers();
    ($this->reconcile)();
    expect(StripeDiscrepancy::open()->count())->toBe(1);

    // the platform ledger is forgotten (a new mode, a restore): the checks cannot run, nothing is "fixed"
    App\Models\StripeSyncCursor::query()->where('account_key', 'platform')->update(['synced_through' => null]);
    $result = ($this->reconcile)();

    expect($result->skipped)->not->toBeEmpty()->and(StripeDiscrepancy::open()->count())->toBe(1);
});

// --- Timing and mode guards -----------------------------------------------------------------------------------------------------

it('does not judge events younger than the grace period or newer than the last sync', function () {
    $x = journey($this);
    $this->travel(10)->minutes();
    resyncLedgers();
    fakeGateway()->alterLedgerTransaction(StripeBalanceTransaction::where('type', 'charge')->value('id'), ['amount' => 5000, 'net' => 4700]);
    resyncLedgers();

    expect(($this->reconcile)()->open)->toHaveCount(0);      // ten minutes old, grace is thirty

    $this->travel(40)->minutes();
    // nothing synced since: the ledger has been read only up to ten minutes after the payment
    App\Models\StripeSyncCursor::query()->where('account_key', 'platform')->update(['synced_through' => $x['order']->paid_at->copy()->subMinutes(5)]);
    expect(($this->reconcile)()->open)->toHaveCount(0);

    resyncLedgers();
    expect(($this->reconcile)()->open)->toHaveCount(1);
});

// --- The mail -------------------------------------------------------------------------------------------------------------------

it('mails the admins the new differences, once, and nobody else', function () {
    $secondAdmin = adminUser();
    $admins = User::role('admin')->get();    // the demo admin too
    $x = journey($this);
    $y = journey($this);
    fakeGateway()->alterLedgerTransaction(collect(fakeGateway()->ledgerTransactions())->firstWhere('source', $x['order']->stripe_charge_id)['id'], ['amount' => 5000, 'net' => 4700]);
    fakeGateway()->forgetLedgerTransaction(collect(fakeGateway()->ledgerTransactions())->firstWhere('source', $y['order']->stripe_charge_id)['id']);
    ($this->settle)();

    ($this->reconcile)(true);

    expect($admins->contains($secondAdmin))->toBeTrue()->and($admins->count())->toBeGreaterThanOrEqual(3);
    Notification::assertSentTo($admins, StripeDifferencesFound::class, fn (StripeDifferencesFound $n) => $n->discrepancies->count() === 2);
    Notification::assertNotSentTo($this->buyer, StripeDifferencesFound::class);
    Notification::assertSentTimes(StripeDifferencesFound::class, $admins->count());
    expect(StripeDiscrepancy::whereNull('notified_at')->count())->toBe(0);

    // the next run finds the same two: nobody is told again
    ($this->reconcile)(true);
    Notification::assertSentTimes(StripeDifferencesFound::class, $admins->count());

    // a third one is mailed on its own
    $z = journey($this);
    fakeGateway()->alterLedgerTransaction(collect(fakeGateway()->ledgerTransactions())->firstWhere('source', $z['order']->stripe_charge_id)['id'], ['amount' => 1, 'net' => 1]);
    ($this->settle)();
    ($this->reconcile)(true);

    Notification::assertSentTimes(StripeDifferencesFound::class, $admins->count() * 2);
    Notification::assertSentTo($this->admin, StripeDifferencesFound::class, fn (StripeDifferencesFound $n) => $n->discrepancies->count() === 1);
});

it('does not mail when the check was run on demand, and the daily run still tells the admins', function () {
    $x = journey($this);
    fakeGateway()->alterLedgerTransaction(collect(fakeGateway()->ledgerTransactions())->firstWhere('type', 'charge')['id'], ['amount' => 5000, 'net' => 4700]);
    ($this->settle)();

    ($this->reconcile)(false);
    Notification::assertNotSentTo($this->admin, StripeDifferencesFound::class);

    ($this->reconcile)(true);
    Notification::assertSentTo($this->admin, StripeDifferencesFound::class);
});

it('mails nothing when everything agrees', function () {
    journey($this);
    ($this->settle)();

    ($this->reconcile)(true);

    Notification::assertNotSentTo($this->admin, StripeDifferencesFound::class);
});

it('writes the mail with the branded look: a table of the differences and a button to the reconciliation page', function () {
    $x = journey($this);
    fakeGateway()->alterLedgerTransaction(collect(fakeGateway()->ledgerTransactions())->firstWhere('type', 'charge')['id'], ['amount' => 5000, 'net' => 4700]);
    ($this->settle)();
    ($this->reconcile)(false);

    $notification = new StripeDifferencesFound(StripeDiscrepancy::open()->get());
    $mail = $notification->toMail($this->admin);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Stripe reconciliation: 1 new difference')
        ->and($html)->toContain('Payment amount differs')->toContain($x['order']->number)->toContain('$100.00')->toContain('$50.00')->toContain('<table')
        ->and($mail->actionUrl)->toContain('/admin/')->and($mail->actionText)->toBe('Open the reconciliation')
        ->and($notification->toArray($this->admin)['kind'])->toBe('stripe_differences');
});

// --- The commands -----------------------------------------------------------------------------------------------------------------

it('runs the reconciliation as a command that syncs first, prints what it found and mails the admins', function () {
    $x = journey($this);
    $other = journey($this);
    fakeGateway()->alterLedgerTransaction(collect(fakeGateway()->ledgerTransactions())->firstWhere('source', $x['order']->stripe_charge_id)['id'], ['amount' => 5000, 'net' => 4700]);
    $this->travel(2)->hours();

    // nothing has been read from Stripe yet: the command reads everything, then compares
    expect(App\Models\StripeBalanceTransaction::count())->toBe(0);
    $this->artisan('stripe:reconcile')->assertSuccessful()->expectsOutputToContain('1 open difference (1 new)')->expectsOutputToContain('Payment amount differs');

    expect(App\Models\StripeBalanceTransaction::count())->toBeGreaterThan(0);
    Notification::assertSentTo($this->admin, StripeDifferencesFound::class);

    // again: still open, not new, nobody is told twice; without syncing it does not ask Stripe
    $calls = fakeGateway()->callCount('listBalanceTransactions');
    $this->artisan('stripe:reconcile --no-sync')->assertSuccessful()->expectsOutputToContain('1 open difference (0 new)');
    expect(fakeGateway()->callCount('listBalanceTransactions'))->toBe($calls);
    Notification::assertSentToTimes($this->admin, StripeDifferencesFound::class, 1);
});

it('does nothing and succeeds while Stripe is not configured', function () {
    config(['marketplace.stripe.secret_key' => null]);
    app()->instance(App\Services\Payments\PaymentGateway::class, new App\Services\Payments\StripeGateway(null));
    app()->forgetInstance(App\Services\Marketplace\Ledger\LedgerSync::class);

    $this->artisan('stripe:reconcile')->assertSuccessful()->expectsOutputToContain('not configured');
});

it('is on the schedule: the ledger sync every ten minutes and the reconciliation once a day', function () {
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events());

    $sync = $events->first(fn ($event) => str_contains($event->command, 'stripe:ledger-sync'));
    $reconcile = $events->first(fn ($event) => str_contains($event->command, 'stripe:reconcile'));
    expect($sync)->not->toBeNull()->and($sync->expression)->toBe('*/10 * * * *')->and($sync->withoutOverlapping)->toBeTrue()
        ->and($sync->command)->toContain('--all')->toContain('--queue')
        ->and($reconcile)->not->toBeNull()->and($reconcile->expression)->toBe('17 4 * * *')->and($reconcile->withoutOverlapping)->toBeTrue();
});
