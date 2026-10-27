<?php

use App\Jobs\SyncStripeLedger;
use App\Models\StripeBalanceTransaction;
use App\Models\StripeSyncCursor;
use App\Services\Marketplace\Ledger\LedgerSync;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
| R6.1 the queued sync (one job per account, unique, retrying with backoff, never breaking a request), the artisan command and the
| webhooks that queue it: the platform endpoint for balance / payout / transfer / refund / dispute events, the Connect endpoint for
| payout and balance events of a seller (`event.account`).
*/

beforeEach(function () {
    config([
        'marketplace.stripe.secret_key' => 'sk_test_ledger',
        'marketplace.stripe.marketplace_webhook_secret' => 'whsec_marketplace_ledger',
        'marketplace.stripe.connect_webhook_secret' => 'whsec_connect_ledger',
    ]);
});

// --- the job ---------------------------------------------------------------------------------------------------------------------

it('is unique per account, runs one per account at a time, and retries with backoff', function () {
    $platform = new SyncStripeLedger;
    $seller = new SyncStripeLedger('acct_seller_1');

    expect($platform)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($platform->uniqueId())->toBe('platform')->and($seller->uniqueId())->toBe('acct_seller_1')
        ->and($platform->backoff())->toBe([30, 120, 300, 900])
        ->and($platform->tries)->toBeGreaterThan($platform->maxExceptions)
        ->and($seller->middleware()[0])->toBeInstanceOf(WithoutOverlapping::class);
});

it('queues one job for an account that is waiting, another for a different account', function () {
    Queue::fake();

    expect(SyncStripeLedger::queueFor(null))->toBeTrue();
    SyncStripeLedger::queueFor(null);
    SyncStripeLedger::queueFor(null);
    SyncStripeLedger::queueFor('acct_seller_1');

    Queue::assertPushed(SyncStripeLedger::class, 2);
    Queue::assertPushed(SyncStripeLedger::class, fn (SyncStripeLedger $job) => $job->account === null);
    Queue::assertPushed(SyncStripeLedger::class, fn (SyncStripeLedger $job) => $job->account === 'acct_seller_1');
});

it('never breaks the request that queues it', function () {
    Log::spy();
    Queue::shouldReceive('push')->andThrow(new RuntimeException('Redis is down'));
    Queue::shouldReceive('connection')->andReturnSelf();

    // a dispatch that fails is logged, not thrown
    expect(fn () => SyncStripeLedger::queueFor('acct_x'))->not->toThrow(Throwable::class);
});

it('mirrors the account when it runs', function () {
    fakeGateway()->recordSubscriptionCharge(1000, 'ch_job');

    SyncStripeLedger::dispatchSync(null);

    expect(StripeBalanceTransaction::where('charge_id', 'ch_job')->exists())->toBeTrue()->and(StripeSyncCursor::forAccount(null)->hasSynced())->toBeTrue();
});

it('queues itself again when a pass stopped at the page limit, until the history is read', function () {
    config(['marketplace.ledger.job_max_pages' => 1]);
    foreach (range(1, 250) as $i) {
        fakeGateway()->recordSubscriptionCharge(500, 'ch_wave_'.$i, ['created' => now()->subMinutes($i)]);
    }
    Queue::fake();
    $sync = app(LedgerSync::class);

    (new SyncStripeLedger)->handle($sync);
    expect(StripeBalanceTransaction::count())->toBe(100);
    Queue::assertPushed(SyncStripeLedger::class, 1);

    // (the queue would run the follow-up once the first job has finished and let go of its lock)
    (new SyncStripeLedger)->handle($sync);
    expect(StripeBalanceTransaction::count())->toBe(200);
    Queue::assertPushed(SyncStripeLedger::class, 1);   // the first follow-up is still waiting: one job per account

    (new SyncStripeLedger)->handle($sync);
    expect(StripeBalanceTransaction::count())->toBe(250)->and(StripeSyncCursor::forAccount(null)->hasSynced())->toBeTrue()
        ->and(fakeGateway()->callCount('listBalanceTransactions'))->toBe(3);
});

it('lets a transient Stripe error through, so the queue retries it', function () {
    fakeGateway()->failOn('listBalanceTransactions');

    expect(fn () => (new SyncStripeLedger)->handle(app(LedgerSync::class)))->toThrow(PaymentGatewayException::class);
    expect(StripeSyncCursor::forAccount(null)->failures)->toBe(1);
});

it('does not retry what Stripe refused for good (an account that is gone)', function () {
    Log::spy();
    $sync = Mockery::mock(LedgerSync::class);
    $sync->shouldReceive('enabled')->andReturn(true);
    $sync->shouldReceive('syncAccount')->once()->andThrow(new PaymentGatewayException('No such account', 0, new Stripe\Exception\InvalidRequestException('No such account', 404)));

    (new SyncStripeLedger('acct_gone'))->handle($sync);

    Log::shouldHaveReceived('warning')->once();
});

it('does nothing while Stripe is not configured', function () {
    config(['marketplace.stripe.secret_key' => null]);
    $sync = new LedgerSync(new App\Services\Payments\StripeGateway(null), app(App\Services\Marketplace\Ledger\LedgerLinker::class));

    (new SyncStripeLedger)->handle($sync);

    expect(StripeSyncCursor::query()->count())->toBe(0);
});

it('queues every account, but waits hours with one that keeps failing', function () {
    Queue::fake();
    $a = onboardedSeller();
    $b = onboardedSeller();
    $sync = app(LedgerSync::class);
    $broken = StripeSyncCursor::forAccount(stripeAccountOf($b));
    $broken->forceFill(['failures' => 5, 'last_error' => 'No such account', 'last_error_at' => now()->subHour()])->save();

    $queued = $sync->queueAll();

    // the platform and the healthy seller; the demo sellers have made-up accounts and are left alone
    expect($queued)->toBe(2);
    Queue::assertPushed(SyncStripeLedger::class, fn ($job) => $job->account === null);
    Queue::assertPushed(SyncStripeLedger::class, fn ($job) => $job->account === stripeAccountOf($a));
    Queue::assertNotPushed(SyncStripeLedger::class, fn ($job) => $job->account === stripeAccountOf($b));

    // a few hours later it gets another try
    Queue::fake();
    $this->travel(7)->hours();
    expect($sync->queueAll())->toBe(3);
});

// --- the command ---------------------------------------------------------------------------------------------------------------------

it('syncs one account, or all of them, from the command line', function () {
    $seller = onboardedSeller();
    $account = stripeAccountOf($seller);
    fakeGateway()->recordSubscriptionCharge(1000, 'ch_cmd');
    fakeGateway()->recordPayout($account, 500, ['id' => 'po_cmd']);

    $this->artisan('stripe:ledger-sync')->assertFailed()->expectsOutputToContain('Say which account');

    $this->artisan('stripe:ledger-sync --account=platform')->assertSuccessful()->expectsOutputToContain('platform: 1 transactions, 0 payouts, 1 page.');
    expect(StripeBalanceTransaction::forAccount(null)->count())->toBe(1);

    $this->artisan("stripe:ledger-sync --account={$account}")->assertSuccessful()->expectsOutputToContain("{$account}: 1 transactions, 1 payouts");

    fakeGateway()->reset();
    $this->artisan('stripe:ledger-sync --all')->assertSuccessful();
    $accounts = collect(fakeGateway()->calls('listBalanceTransactions'))->pluck('args.account')->all();
    expect($accounts)->toContain(null)->toContain($account)->and(count($accounts))->toBe(count(app(LedgerSync::class)->accounts()));
});

it('backfills from a date', function () {
    fakeGateway()->recordSubscriptionCharge(1000, 'ch_ancient', ['created' => now()->subDays(40)]);
    fakeGateway()->recordSubscriptionCharge(1000, 'ch_recent', ['created' => now()->subDays(2)]);

    $this->artisan('stripe:ledger-sync --account=platform --since='.now()->subDays(10)->toDateString())->assertSuccessful();
    expect(StripeBalanceTransaction::pluck('charge_id')->all())->toBe(['ch_recent']);

    $this->artisan('stripe:ledger-sync --account=platform --since='.now()->subDays(60)->toDateString())->assertSuccessful();
    expect(StripeBalanceTransaction::count())->toBe(2);

    $this->artisan('stripe:ledger-sync --account=platform --since=not-a-date')->assertFailed()->expectsOutputToContain('--since must be a date');
});

it('reports a failing account and carries on with the others', function () {
    $seller = onboardedSeller();
    fakeGateway()->failOn('listBalanceTransactions');

    $this->artisan('stripe:ledger-sync --all')->assertFailed()->expectsOutputToContain('platform: Fake gateway failure');

    // the second account (the first seller) was still read
    expect(StripeSyncCursor::query()->whereNotNull('synced_through')->count())->toBeGreaterThan(0);
});

it('queues instead of running when asked to (what the scheduler does)', function () {
    Queue::fake();
    onboardedSeller();

    $this->artisan('stripe:ledger-sync --all --queue')->assertSuccessful()->expectsOutputToContain('Queued 2 ledger syncs');
    Queue::assertPushed(SyncStripeLedger::class, 2);
    expect(StripeBalanceTransaction::count())->toBe(0);

    $this->artisan('stripe:ledger-sync --account=platform --queue')->assertSuccessful();
});

it('says so and succeeds while Stripe is not configured', function () {
    config(['marketplace.stripe.secret_key' => null]);
    app()->instance(App\Services\Payments\PaymentGateway::class, new App\Services\Payments\StripeGateway(null));
    app()->forgetInstance(LedgerSync::class);

    $this->artisan('stripe:ledger-sync --all')->assertSuccessful()->expectsOutputToContain('not configured');
});

// --- the webhooks -----------------------------------------------------------------------------------------------------------------------

function ledgerEvent(string $type, array $object = [], array $extra = []): array
{
    return stripeEventPayload($type, $object + ['id' => 'obj_'.Illuminate\Support\Str::random(8)], $extra);
}

it('queues a sync of the platform for the events the platform endpoint is told about', function (string $type) {
    Queue::fake();

    postStripeEvent($this, 'stripe.marketplace.webhook', ledgerEvent($type), 'whsec_marketplace_ledger')->assertOk()->assertJson(['status' => 'handled']);

    Queue::assertPushed(SyncStripeLedger::class, fn (SyncStripeLedger $job) => $job->account === null);
    Queue::assertPushed(SyncStripeLedger::class, 1);
})->with([
    'balance.available', 'payout.created', 'payout.paid', 'payout.failed', 'payout.canceled', 'payout.updated',
    'transfer.created', 'transfer.reversed', 'transfer.updated', 'charge.dispute.funds_withdrawn', 'charge.dispute.funds_reinstated',
]);

it('also queues a sync after the order side of a refund or a dispute has been handled', function (string $type) {
    Queue::fake();
    $x = chargedListingOrder();
    $object = ['id' => $type === 'charge.refunded' ? $x['order']->stripe_charge_id : 'du_1', 'payment_intent' => $x['order']->stripe_payment_intent_id, 'charge' => $x['order']->stripe_charge_id, 'amount' => 6900, 'amount_refunded' => 0, 'reason' => 'fraudulent'];

    postStripeEvent($this, 'stripe.marketplace.webhook', ledgerEvent($type, $object), 'whsec_marketplace_ledger')->assertOk();

    Queue::assertPushed(SyncStripeLedger::class, fn (SyncStripeLedger $job) => $job->account === null);
})->with(['charge.refunded', 'charge.dispute.created', 'charge.dispute.closed']);

it('queues the platform sync even for a refund of a charge that is not an order (Wave)', function () {
    Queue::fake();

    postStripeEvent($this, 'stripe.marketplace.webhook', ledgerEvent('charge.refunded', ['id' => 'ch_wave', 'payment_intent' => 'pi_wave', 'amount' => 1900, 'amount_refunded' => 1900]), 'whsec_marketplace_ledger')
        ->assertOk()->assertJson(['status' => 'ignored']);

    Queue::assertPushed(SyncStripeLedger::class, fn (SyncStripeLedger $job) => $job->account === null);
});

it('queues the seller account too when a transfer went to it', function () {
    Queue::fake();
    $seller = onboardedSeller();
    $account = stripeAccountOf($seller);

    postStripeEvent($this, 'stripe.marketplace.webhook', ledgerEvent('transfer.created', ['destination' => $account, 'amount' => 6300]), 'whsec_marketplace_ledger')->assertOk();
    postStripeEvent($this, 'stripe.marketplace.webhook', ledgerEvent('transfer.created', ['destination' => 'acct_not_ours']), 'whsec_marketplace_ledger')->assertOk();

    Queue::assertPushed(SyncStripeLedger::class, fn (SyncStripeLedger $job) => $job->account === $account);
    Queue::assertNotPushed(SyncStripeLedger::class, fn (SyncStripeLedger $job) => $job->account === 'acct_not_ours');
    Queue::assertPushed(SyncStripeLedger::class, 2);     // the platform once (the second event found one waiting), the seller once
});

it('ignores the events it does not know, as before', function (string $type) {
    Queue::fake();

    postStripeEvent($this, 'stripe.marketplace.webhook', ledgerEvent($type), 'whsec_marketplace_ledger')->assertOk()->assertJson(['status' => 'ignored']);

    Queue::assertNothingPushed();
})->with(['customer.subscription.updated', 'invoice.paid', 'charge.succeeded', 'account.updated', 'payment_intent.created', 'payouts.created']);

it('queues a sync of the seller named by a Connect event, and of nobody else', function (string $type) {
    Queue::fake();
    $seller = onboardedSeller();
    $account = stripeAccountOf($seller);

    postStripeEvent($this, 'stripe.connect.webhook', ledgerEvent($type, [], ['account' => $account]), 'whsec_connect_ledger')->assertOk()->assertJson(['status' => 'handled']);

    Queue::assertPushed(SyncStripeLedger::class, 1);
    Queue::assertPushed(SyncStripeLedger::class, fn (SyncStripeLedger $job) => $job->account === $account);
})->with(['payout.paid', 'payout.failed', 'payout.created', 'payout.updated', 'payout.canceled', 'balance.available']);

it('ignores Connect events of an unknown account or without one', function () {
    Queue::fake();

    postStripeEvent($this, 'stripe.connect.webhook', ledgerEvent('payout.paid', [], ['account' => 'acct_stranger']), 'whsec_connect_ledger')->assertOk()->assertJson(['status' => 'ignored']);
    postStripeEvent($this, 'stripe.connect.webhook', ledgerEvent('payout.paid'), 'whsec_connect_ledger')->assertOk()->assertJson(['status' => 'ignored']);
    postStripeEvent($this, 'stripe.connect.webhook', ledgerEvent('transfer.created', [], ['account' => stripeAccountOf(onboardedSeller())]), 'whsec_connect_ledger')->assertOk()->assertJson(['status' => 'ignored']);

    Queue::assertNothingPushed();
});

it('still handles account.updated on the Connect endpoint', function () {
    Queue::fake();
    $seller = App\Models\User::factory()->create();
    $profile = App\Models\SellerProfile::factory()->pending()->for($seller)->create();

    postStripeEvent($this, 'stripe.connect.webhook', ledgerEvent('account.updated', ['id' => $profile->stripe_account_id, 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true]), 'whsec_connect_ledger')
        ->assertOk()->assertJson(['status' => 'handled']);

    expect($profile->fresh()->canReceivePayouts())->toBeTrue();
    Queue::assertNothingPushed();
});

it('answers a webhook even when the queue is down', function () {
    Queue::shouldReceive('push')->andThrow(new RuntimeException('queue down'));
    Queue::shouldReceive('connection')->andReturnSelf();

    postStripeEvent($this, 'stripe.marketplace.webhook', ledgerEvent('payout.paid'), 'whsec_marketplace_ledger')->assertOk();
});

it('reads the ledger as a result of a webhook (the queue runs inline in tests), de-duplicated by event id', function () {
    fakeGateway()->recordSubscriptionCharge(1000, 'ch_hook');
    $event = ledgerEvent('balance.available');

    postStripeEvent($this, 'stripe.marketplace.webhook', $event, 'whsec_marketplace_ledger')->assertOk()->assertJson(['status' => 'handled']);
    expect(StripeBalanceTransaction::where('charge_id', 'ch_hook')->exists())->toBeTrue();

    // the same event again is a duplicate: no second read
    $calls = fakeGateway()->callCount('listBalanceTransactions');
    postStripeEvent($this, 'stripe.marketplace.webhook', $event, 'whsec_marketplace_ledger')->assertOk()->assertJson(['status' => 'duplicate']);
    expect(fakeGateway()->callCount('listBalanceTransactions'))->toBe($calls);
});
