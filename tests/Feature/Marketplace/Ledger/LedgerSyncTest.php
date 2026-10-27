<?php

use App\Enums\LedgerLink;
use App\Models\Order;
use App\Models\StripeBalance;
use App\Models\StripeBalanceTransaction;
use App\Models\StripePayout;
use App\Models\StripeSyncCursor;
use App\Services\Marketplace\Ledger\LedgerSync;
use App\Services\Payments\Exceptions\PaymentGatewayException;

/*
| R6.1: the sync of the Stripe ledger mirror - incremental, idempotent, resumable, per account and per mode.
*/

beforeEach(function () {
    config(['marketplace.fee_bps' => 1000, 'marketplace.physical_payout_trigger' => 'shipped', 'marketplace.stripe.secret_key' => 'sk_test_ledger']);
    $this->sync = app(LedgerSync::class);
});

it('mirrors the balance transactions of the platform, field for field', function () {
    $x = chargedListingOrder();
    $charge = $x['charge'];

    $result = syncLedger();

    $row = StripeBalanceTransaction::findOrFail($charge['id']);
    expect($result->transactions)->toBe(1)->and($result->complete)->toBeTrue()->and($result->label())->toBe('platform')
        ->and($row->account)->toBeNull()
        ->and($row->livemode)->toBeFalse()
        ->and($row->type)->toBe('charge')
        ->and($row->reporting_category)->toBe('charge')
        ->and($row->amount_cents)->toBe(6900)         // 6000 + 900 shipping
        ->and($row->fee_cents)->toBe(230)             // 2.9 % + 30 cents
        ->and($row->net_cents)->toBe(6670)
        ->and($row->currency)->toBe('usd')
        ->and($row->stripe_created_at->getTimestamp())->toBe($charge['created'])
        ->and($row->available_on->getTimestamp())->toBe($charge['available_on'])
        ->and($row->source_id)->toBe($x['order']->stripe_charge_id)
        ->and($row->source_type)->toBe('charge')
        ->and($row->charge_id)->toBe($x['order']->stripe_charge_id)
        ->and($row->payment_intent_id)->toBe($x['order']->stripe_payment_intent_id)
        ->and($row->transfer_group)->toBe($x['order']->number)
        ->and($row->fee_details[0]['type'])->toBe('stripe_fee')
        ->and($row->description)->toBe('Order '.$x['order']->number)
        ->and($row->isPending())->toBeTrue()          // a card payment is available two days later
        ->and($row->link_status)->toBe(LedgerLink::LINKED)
        ->and($row->order_id)->toBe($x['order']->id);
});

it('moves the cursor only when a pass reaches its end', function () {
    chargedListingOrder();
    $this->travelTo(now()->startOfSecond());

    $cursor = StripeSyncCursor::forAccount(null);
    expect($cursor->hasSynced())->toBeFalse();

    syncLedger();

    $cursor = StripeSyncCursor::forAccount(null);
    expect($cursor->hasSynced())->toBeTrue()
        ->and($cursor->synced_through->equalTo(now()))->toBeTrue()
        ->and($cursor->last_synced_at)->not->toBeNull()
        ->and($cursor->last_error)->toBeNull()
        ->and($cursor->resume_after)->toBeNull();
});

it('is idempotent: a second sync changes nothing, a changed object is updated, links stay', function () {
    $x = chargedListingOrder();
    syncLedger();
    $first = StripeBalanceTransaction::findOrFail($x['charge']['id']);

    $this->travel(5)->minutes();
    syncLedger();
    syncLedger();

    expect(StripeBalanceTransaction::count())->toBe(1);
    $again = StripeBalanceTransaction::findOrFail($x['charge']['id']);
    expect($again->link_status)->toBe(LedgerLink::LINKED)->and($again->order_id)->toBe($x['order']->id)
        ->and($again->created_at->equalTo($first->created_at))->toBeTrue();

    // Stripe changes the description: the row follows, its links do not come undone.
    fakeGateway()->alterLedgerTransaction($x['charge']['id'], ['description' => 'Edited in the dashboard']);
    syncLedger();

    $edited = StripeBalanceTransaction::findOrFail($x['charge']['id']);
    expect($edited->description)->toBe('Edited in the dashboard')->and($edited->order_id)->toBe($x['order']->id)->and($edited->link_status)->toBe(LedgerLink::LINKED);
});

it('reads from before the end of the last pass, so a late transaction is still caught (the overlap window)', function () {
    $this->travelTo(now()->startOfSecond());
    fakeGateway()->recordSubscriptionCharge(1000, 'ch_wave_old', ['created' => now()->subHours(3)]);
    syncLedger();
    expect(StripeBalanceTransaction::count())->toBe(1);

    $this->travel(20)->minutes();

    // Stripe shows two transactions late: one created 10 minutes before the last pass ended, one two hours before.
    fakeGateway()->recordSubscriptionCharge(1000, 'ch_wave_late', ['created' => now()->subMinutes(30)]);
    fakeGateway()->recordSubscriptionCharge(1000, 'ch_wave_too_late', ['created' => now()->subHours(2)]);
    syncLedger();

    expect(StripeBalanceTransaction::where('charge_id', 'ch_wave_late')->exists())->toBeTrue()
        ->and(StripeBalanceTransaction::where('charge_id', 'ch_wave_too_late')->exists())->toBeFalse();

    // The backfill command reads further back on request.
    syncLedger(null, now()->subHours(5));
    expect(StripeBalanceTransaction::where('charge_id', 'ch_wave_too_late')->exists())->toBeTrue()
        ->and(StripeBalanceTransaction::count())->toBe(3);
});

it('reads every page of a long history', function () {
    foreach (range(1, 250) as $i) {
        fakeGateway()->recordSubscriptionCharge(500 + $i, 'ch_wave_'.$i, ['created' => now()->subMinutes($i)]);
    }

    $result = syncLedger();

    expect($result->transactions)->toBe(250)->and($result->pages)->toBe(3)->and($result->complete)->toBeTrue()
        ->and(StripeBalanceTransaction::count())->toBe(250)
        ->and(fakeGateway()->callCount('listBalanceTransactions'))->toBe(3);
    $starts = array_map(fn ($call) => $call['args']['startingAfter'], fakeGateway()->calls('listBalanceTransactions'));
    expect($starts[0])->toBeNull()->and($starts[1])->not->toBeNull()->and($starts[2])->not->toBe($starts[1]);
});

it('stops at the page limit, remembers where, and the next run continues instead of starting over', function () {
    foreach (range(1, 250) as $i) {
        fakeGateway()->recordSubscriptionCharge(500, 'ch_wave_'.$i, ['created' => now()->subMinutes($i)]);
    }

    $partial = $this->sync->syncAccount(null, maxPages: 1);

    $cursor = StripeSyncCursor::forAccount(null);
    $stoppedAfter = $cursor->resume_after;
    expect($partial->complete)->toBeFalse()->and($partial->transactions)->toBe(100)
        ->and($cursor->hasSynced())->toBeFalse()
        ->and($stoppedAfter)->not->toBeNull()
        ->and(StripeBalanceTransaction::count())->toBe(100);

    $second = $this->sync->syncAccount(null, maxPages: 5);

    $calls = fakeGateway()->calls('listBalanceTransactions');
    expect($second->complete)->toBeTrue()
        // it went on from where the first run stopped and read the other 150
        ->and($calls[1]['args']['startingAfter'])->toBe($stoppedAfter)
        ->and($second->transactions)->toBe(150)
        ->and(StripeBalanceTransaction::count())->toBe(250)
        ->and(StripeSyncCursor::forAccount(null)->hasSynced())->toBeTrue()
        ->and(StripeSyncCursor::forAccount(null)->resume_after)->toBeNull();
});

it('writes a Stripe failure on the cursor, throws it, and a later run clears it', function () {
    chargedListingOrder();
    fakeGateway()->failOn('listBalanceTransactions');

    expect(fn () => syncLedger())->toThrow(PaymentGatewayException::class);

    $cursor = StripeSyncCursor::forAccount(null);
    expect($cursor->last_error)->toContain('listBalanceTransactions')->and($cursor->failures)->toBe(1)->and($cursor->hasSynced())->toBeFalse()
        ->and(StripeBalanceTransaction::count())->toBe(0);

    syncLedger();

    $cursor = StripeSyncCursor::forAccount(null);
    expect($cursor->last_error)->toBeNull()->and($cursor->failures)->toBe(0)->and($cursor->hasSynced())->toBeTrue()
        ->and(StripeBalanceTransaction::count())->toBe(1);
});

it('syncs a connected account through the Stripe-Account header and keeps its rows apart', function () {
    $x = chargedListingOrder();
    app(App\Services\Marketplace\ShipmentService::class)->markShipped($x['seller'], $x['sellerOrder'], 'UPS', '1Z1', null);
    $account = stripeAccountOf($x['seller']);

    syncLedger();
    syncLedger($account);

    $calls = fakeGateway()->calls('listBalanceTransactions');
    expect(collect($calls)->pluck('args.account')->all())->toBe([null, $account]);

    $platform = StripeBalanceTransaction::forAccount(null)->pluck('type')->sort()->values()->all();
    $seller = StripeBalanceTransaction::forAccount($account)->pluck('type')->all();
    expect($platform)->toBe(['charge', 'transfer'])
        ->and($seller)->toBe(['payment'])
        ->and(StripeSyncCursor::query()->pluck('account_key')->sort()->values()->all())->toBe(collect([$account, 'platform'])->sort()->values()->all());
});

it('mirrors payouts, follows their status, and shows the destination bank', function () {
    $seller = onboardedSeller();
    $account = stripeAccountOf($seller);
    $payout = fakeGateway()->recordPayout($account, 4200, ['status' => 'in_transit', 'id' => 'po_seller_1']);

    syncLedger($account);

    $row = StripePayout::findOrFail('po_seller_1');
    expect($row->account)->toBe($account)->and($row->seller_id)->toBe($seller->id)
        ->and($row->amount_cents)->toBe(4200)->and($row->status)->toBe('in_transit')->and($row->isOnTheWay())->toBeTrue()
        ->and($row->type)->toBe('bank_account')->and($row->method)->toBe('standard')
        ->and($row->destination_label)->toBe('STRIPE TEST BANK ••••6789')
        ->and($row->arrival_date->getTimestamp())->toBe($payout['arrival_date'])
        ->and($row->failureExplanation())->toBeNull();

    // It arrives; a later one fails.
    $this->travel(3)->days();
    fakeGateway()->setPayoutStatus('po_seller_1', 'paid');
    fakeGateway()->recordPayout($account, 1000, ['id' => 'po_seller_2', 'status' => 'failed', 'failure_code' => 'account_closed']);
    syncLedger($account);

    expect(StripePayout::findOrFail('po_seller_1')->status)->toBe('paid')
        ->and(StripePayout::findOrFail('po_seller_1')->statusLabel())->toBe('Paid')
        ->and(StripePayout::findOrFail('po_seller_2')->isFailed())->toBeTrue()
        ->and(StripePayout::findOrFail('po_seller_2')->failureExplanation())->toContain('bank account is closed')
        ->and(StripePayout::count())->toBe(2);
});

it('keeps a balance per account and currency: available follows available_on, in transit is the payouts on their way', function () {
    $this->travelTo(now()->startOfSecond());
    fakeGateway()->recordSubscriptionCharge(10000, 'ch_wave_a', ['fee' => 0, 'created' => now()->subDays(5)]);   // available since 3 days
    fakeGateway()->recordSubscriptionCharge(2500, 'ch_wave_b', ['fee' => 0]);                                    // available in 2 days
    fakeGateway()->recordPayout(null, 3000, ['status' => 'in_transit', 'created' => now()->subHour()]);

    syncLedger();

    $balance = StripeBalance::query()->forAccount(null)->where('currency', 'usd')->firstOrFail();
    expect($balance->available_cents)->toBe(7000)     // 10000 - the payout of 3000
        ->and($balance->pending_cents)->toBe(2500)
        ->and($balance->in_transit_cents)->toBe(3000)
        ->and($balance->connect_reserved_cents)->toBe(0)
        ->and($balance->refreshed_at->equalTo(now()))->toBeTrue();

    // Two days later the second charge is available.
    $this->travel(3)->days();
    syncLedger();

    $balance->refresh();
    expect($balance->available_cents)->toBe(9500)->and($balance->pending_cents)->toBe(0)
        ->and(StripeBalance::count())->toBe(1);   // updated, not duplicated
});

it('stores the mode of the keys on everything it mirrors and keeps test and live apart', function () {
    $x = chargedListingOrder();
    syncLedger();
    expect(StripeBalanceTransaction::where('livemode', true)->count())->toBe(0);

    config(['marketplace.stripe.secret_key' => 'sk_live_abc']);
    fakeGateway()->recordSubscriptionCharge(900, 'ch_wave_live');
    syncLedger();

    expect(StripeBalanceTransaction::where('livemode', true)->pluck('charge_id')->all())->not->toBeEmpty()
        // the same ids are read again under the live mode: rows exist once per id, the cursors once per mode
        ->and(StripeSyncCursor::query()->where('account_key', 'platform')->pluck('livemode')->sort()->values()->all())->toBe([false, true])
        ->and(StripeBalance::query()->where('account_key', 'platform')->pluck('livemode')->sort()->values()->all())->toBe([false, true]);
    expect(StripeBalanceTransaction::currentMode()->count())->toBe(StripeBalanceTransaction::where('livemode', true)->count());
});

it('queues no work while Stripe is not configured, and works with the fake gateway of local development', function () {
    expect($this->sync->enabled())->toBeTrue();   // fake gateway in tests

    config(['marketplace.stripe.secret_key' => null]);
    $real = new LedgerSync(new App\Services\Payments\StripeGateway(null), app(App\Services\Marketplace\Ledger\LedgerLinker::class));
    expect($real->enabled())->toBeFalse()->and($real->queueAll())->toBe(0);
});

it('lists the platform and every seller with a connected account as the accounts to sync', function () {
    $a = onboardedSeller();
    $b = onboardedSeller();
    App\Models\SellerProfile::factory()->withoutStripe()->for(App\Models\User::factory()->create())->create();

    $accounts = $this->sync->accounts();

    // (the demo sellers come first)
    expect($accounts[0])->toBeNull()
        ->and(array_slice($accounts, -2))->toBe([stripeAccountOf($a), stripeAccountOf($b)])
        ->and(array_filter($accounts, fn ($account) => $account === null))->toHaveCount(1);
});

it('fills the real Stripe fee of an order from its charge, also when the order is paid after the charge was read', function () {
    $buyer = App\Models\User::factory()->create();
    $listing = sellableListing(onboardedSeller(), 6000, ['shipping_domestic_cents' => 900, 'quantity' => 3]);
    $order = pendingOrder($buyer, [$listing], addressFor($buyer));

    // Stripe books the payment first (the order still pending here), the webhook arrives later.
    $order->forceFill(['stripe_charge_id' => 'ch_late_hook', 'stripe_payment_intent_id' => 'pi_late_hook'])->save();
    fakeGateway()->recordOrderCharge($order, ['fee' => 1234]);
    $order->forceFill(['stripe_charge_id' => null, 'stripe_payment_intent_id' => null])->save();
    syncLedger();
    expect($order->fresh()->stripe_fee_cents)->toBeNull()
        // linked through the order marker in the metadata even though the order does not know its charge yet
        ->and(StripeBalanceTransaction::where('charge_id', 'ch_late_hook')->value('order_id'))->toBe($order->id);

    payOrder($order, 'pi_late_hook', 'ch_late_hook');
    $this->travel(10)->minutes();
    syncLedger();

    expect($order->fresh()->stripe_fee_cents)->toBe(1234);
});

it('does not touch the fee of orders without a charge in the ledger, nor orders it cannot match', function () {
    $other = Order::factory()->paid()->create();
    chargedListingOrder();
    syncLedger();

    expect($other->fresh()->stripe_fee_cents)->toBeNull()
        ->and(Order::whereNotNull('stripe_fee_cents')->count())->toBe(1);
});
