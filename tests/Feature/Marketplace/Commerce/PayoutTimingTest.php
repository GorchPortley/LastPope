<?php

use App\Enums\IssueResolution;
use App\Enums\IssueStatus;
use App\Enums\SellerOrderStatus;
use App\Models\OrderIssue;
use App\Models\User;
use App\Notifications\PayoutSent;
use App\Services\Marketplace\PayoutService;
use App\Services\Marketplace\ShipmentService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| R2.1: physical seller orders are paid out physical_payout_delay_days (14) after the seller marked them shipped;
| digital ones at payment; confirming receipt changes nothing; an unresolved problem report holds the payout.
*/

beforeEach(function () {
    Storage::fake('private');
    config([
        'marketplace.fee_bps' => 1000, 'marketplace.fee_fixed_cents' => 0,
        'marketplace.physical_payout_trigger' => 'shipped_delay', 'marketplace.physical_payout_delay_days' => 14,
        'marketplace.auto_complete_days' => 14, 'marketplace.issue_window_days' => 14, 'marketplace.issue_escalation_days' => 3,
    ]);
    $this->payouts = app(PayoutService::class);
});

it('ships with shipped_delay and 14 days as the defaults', function () {
    // the config file's defaults, not this test's overrides
    $defaults = require config_path('marketplace.php');

    expect($defaults['physical_payout_trigger'])->toBeIn(['shipped_delay'])
        ->and($defaults['physical_payout_delay_days'])->toBe(14)
        ->and($defaults['issue_window_days'])->toBe(14)
        ->and($defaults['issue_escalation_days'])->toBe(3);
});

it('pays a physical seller order 14 days after shipping, not before, and the hourly job does it', function () {
    Notification::fake();
    $x = reportableOrder();
    $so = $x['sellerOrder'];

    expect($so->status)->toBe(SellerOrderStatus::SHIPPED)
        ->and($so->isTransferred())->toBeFalse()
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(0)
        ->and($this->payouts->isDue($so))->toBeFalse()
        ->and($this->payouts->dueAt($so)->equalTo($so->shipped_at->copy()->addDays(14)))->toBeTrue();

    $this->travel(13)->days();
    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect($so->fresh()->isTransferred())->toBeFalse();

    $this->travel(2)->days(); // day 15
    expect($this->payouts->isDue($so->fresh()))->toBeTrue();
    $this->artisan('marketplace:auto-complete')->assertSuccessful();

    $paid = $so->fresh();
    expect($paid->isTransferred())->toBeTrue()
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(1)
        ->and(fakeGateway()->lastCall('createTransfer')['amountCents'])->toBe(6000 + 900 - 600)
        ->and($paid->status)->toBe(SellerOrderStatus::COMPLETED); // auto-complete ran in the same job
    Notification::assertSentTo($x['seller'], PayoutSent::class, 1);

    // and only once
    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect(fakeGateway()->callCount('createTransfer'))->toBe(1);
});

it('is exactly due at the end of the delay', function () {
    $this->travelTo(now()->startOfSecond());
    $x = reportableOrder();
    $so = $x['sellerOrder'];

    $this->travelTo($so->shipped_at->copy()->addDays(14)->subSecond());
    expect($this->payouts->isDue($so->fresh()))->toBeFalse();

    $this->travelTo($so->shipped_at->copy()->addDays(14));
    expect($this->payouts->isDue($so->fresh()))->toBeTrue();
});

it('does not pay out earlier when the buyer confirms receipt', function () {
    $x = reportableOrder();
    $so = $x['sellerOrder'];

    app(ShipmentService::class)->confirmReceived($x['buyer'], $so);

    $so->refresh();
    expect($so->status)->toBe(SellerOrderStatus::COMPLETED)
        ->and($so->delivered_at)->not->toBeNull()
        ->and($so->isTransferred())->toBeFalse()
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(0)
        ->and($this->payouts->isDue($so))->toBeFalse();

    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect($so->fresh()->isTransferred())->toBeFalse();

    $this->travel(14)->days();
    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect($so->fresh()->isTransferred())->toBeTrue();
});

it('pays digital seller orders at payment whatever the trigger is', function (string $trigger) {
    config(['marketplace.physical_payout_trigger' => $trigger]);
    $buyer = User::factory()->create();
    $seller = onboardedSeller();
    $order = pendingOrder($buyer, [paidDesign($seller, 3000)]);
    payOrder($order, 'pi_digital', 'ch_digital');

    expect($order->sellerOrders()->first()->isTransferred())->toBeTrue()
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(1);
})->with(['shipped_delay', 'shipped', 'paid']);

it('keeps the "shipped" trigger: paid out as soon as the seller ships', function () {
    config(['marketplace.physical_payout_trigger' => 'shipped']);
    $x = reportableOrder('paid');
    expect($x['sellerOrder']->isTransferred())->toBeFalse();

    app(ShipmentService::class)->markShipped($x['seller'], $x['sellerOrder'], 'UPS', '1Z1');

    expect($x['sellerOrder']->fresh()->isTransferred())->toBeTrue()
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(1);
});

it('keeps the "paid" trigger: paid out when the payment succeeds', function () {
    config(['marketplace.physical_payout_trigger' => 'paid']);
    $x = reportableOrder('paid');

    expect($x['sellerOrder']->isTransferred())->toBeTrue()
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(1);
});

it('treats an unknown trigger like shipped_delay', function () {
    config(['marketplace.physical_payout_trigger' => 'whenever']);
    $x = reportableOrder();

    expect($this->payouts->trigger())->toBe('shipped_delay')
        ->and($x['sellerOrder']->isTransferred())->toBeFalse();
});

it('honours another delay', function () {
    config(['marketplace.physical_payout_delay_days' => 5]);
    $x = reportableOrder();

    $this->travel(4)->days();
    expect($this->payouts->isDue($x['sellerOrder']->fresh()))->toBeFalse();
    $this->travel(2)->days();
    expect($this->payouts->isDue($x['sellerOrder']->fresh()))->toBeTrue();
});

it('retries a failed transfer on the next hourly run', function () {
    $x = reportableOrder();
    $this->travel(15)->days();
    fakeGateway()->failOn('createTransfer', 2); // the completion and the payout release of the first run both fail

    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect($x['sellerOrder']->fresh()->isTransferred())->toBeFalse()
        ->and($x['sellerOrder']->fresh()->status)->toBe(SellerOrderStatus::COMPLETED);

    $this->travel(1)->hour();
    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect($x['sellerOrder']->fresh()->isTransferred())->toBeTrue()
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(3);
});

it('holds the payout while a problem report is unresolved and releases it after the resolution', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    $so = $x['sellerOrder'];

    $this->travel(15)->days(); // past the delay
    expect($this->payouts->isDue($so->fresh()))->toBeFalse()
        ->and($this->payouts->holdReason($so->fresh()))->toBe('issue');

    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect($so->fresh()->isTransferred())->toBeFalse()
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(0);

    // the buyer withdraws: the delay is over, so the payout is released at once
    issues()->withdraw($x['buyer'], $issue);

    expect($issue->fresh()->status)->toBe(IssueStatus::RESOLVED)
        ->and($so->fresh()->isTransferred())->toBeTrue()
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(1);
});

it('waits for the date after a resolution that comes before the 14 days are over', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    $this->travel(2)->days();
    issues()->withdraw($x['buyer'], $issue);
    expect($x['sellerOrder']->fresh()->isTransferred())->toBeFalse();

    $this->travel(13)->days(); // day 15
    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect($x['sellerOrder']->fresh()->isTransferred())->toBeTrue();
});

it('holds a pending payout back for an unresolved problem under the "paid" and "shipped" triggers too', function (string $trigger, string $stage) {
    config(['marketplace.physical_payout_trigger' => $trigger]);
    fakeGateway()->failOn('createTransfer'); // the payout is due but still pending
    $x = reportableOrder($stage);
    expect($x['sellerOrder']->isTransferred())->toBeFalse();

    $issue = reportProblem($x);
    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect($x['sellerOrder']->fresh()->isTransferred())->toBeFalse();

    issues()->withdraw($x['buyer'], $issue);
    expect($x['sellerOrder']->fresh()->isTransferred())->toBeTrue();
})->with([
    'paid' => ['paid', 'paid'],
    'shipped' => ['shipped', 'shipped'],
]);

it('does not auto-complete a seller order with an unresolved problem report, and completes it afterwards', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    $this->travel(15)->days();
    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect($x['sellerOrder']->fresh()->status)->toBe(SellerOrderStatus::SHIPPED);
    expect(app(ShipmentService::class)->autoComplete($x['sellerOrder']->fresh()))->toBeFalse();

    issues()->withdraw($x['buyer'], $issue);
    $this->artisan('marketplace:auto-complete')->assertSuccessful();

    expect($x['sellerOrder']->fresh()->status)->toBe(SellerOrderStatus::COMPLETED);
});

it('does not pay out after a lost bank dispute until an admin decides', function () {
    $x = reportableOrder();
    $so = $x['sellerOrder'];
    OrderIssue::factory()->forSellerOrder($so)->dispute('dp_lost')->resolved(IssueResolution::CHARGEBACK_LOST)->create();

    $this->travel(20)->days();

    expect($this->payouts->isDue($so->fresh()))->toBeFalse()
        ->and($this->payouts->holdReason($so->fresh()))->toBe('dispute_lost');
    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect($so->fresh()->isTransferred())->toBeFalse();
});

it('describes where a payout stands for the dashboards', function () {
    $x = reportableOrder('paid');
    $so = $x['sellerOrder'];

    // not shipped: no date yet
    expect($this->payouts->outlook($so))->toMatchArray(['state' => 'after_ship', 'at' => null, 'reason' => null]);

    app(ShipmentService::class)->markShipped($x['seller'], $so, 'UPS', '1Z');
    $outlook = $this->payouts->outlook($so->fresh());
    expect($outlook['state'])->toBe('scheduled')
        ->and($outlook['at']->equalTo($so->fresh()->shipped_at->copy()->addDays(14)))->toBeTrue();

    $issue = reportProblem(['buyer' => $x['buyer'], 'sellerOrder' => $so]);
    expect($this->payouts->outlook($so->fresh()))->toMatchArray(['state' => 'held', 'reason' => 'issue']);

    issues()->withdraw($x['buyer'], $issue);
    $this->travel(14)->days();
    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect($this->payouts->outlook($so->fresh())['state'])->toBe('paid_out');

    // platform sellers and refunded orders have none
    $store = storeListing();
    $buyer = User::factory()->create();
    $order = pendingOrder($buyer, [$store], addressFor($buyer));
    payOrder($order, 'pi_store', 'ch_store');
    expect($this->payouts->outlook($order->sellerOrders()->first())['state'])->toBe('none');
});

it('words the payout timing for sellers by trigger', function () {
    expect($this->payouts->timingPhrase())->toBe('14 days after you mark the parcel as shipped')
        ->and($this->payouts->timingNote())->toContain('Digital items are paid out right after the payment')->toContain('problem');

    config(['marketplace.physical_payout_delay_days' => 1]);
    expect($this->payouts->timingPhrase())->toBe('1 day after you mark the parcel as shipped');

    config(['marketplace.physical_payout_trigger' => 'shipped']);
    expect($this->payouts->timingPhrase())->toBe('as soon as you mark the parcel as shipped');

    config(['marketplace.physical_payout_trigger' => 'paid']);
    expect($this->payouts->timingPhrase())->toBe('right after the buyer pays');
});

it('runs marketplace:auto-complete every hour', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events->contains(fn ($event) => str_contains($event->command, 'marketplace:auto-complete') && $event->expression === '0 * * * *'))->toBeTrue();
});

it('reports what a dry run would do', function () {
    $x = reportableOrder();
    $this->travel(15)->days();

    // (the demo data has parcels and payouts of its own, so count at least ours)
    Artisan::call('marketplace:auto-complete', ['--dry-run' => true]);
    preg_match('/(\d+) shipped seller order\(s\) older than 14 days would be completed; (\d+) payout\(s\) are due/', Artisan::output(), $m);

    expect($m)->toHaveCount(3)->and((int) $m[1])->toBeGreaterThanOrEqual(1)->and((int) $m[2])->toBeGreaterThanOrEqual(1)
        ->and($x['sellerOrder']->fresh()->isTransferred())->toBeFalse()
        ->and($x['sellerOrder']->fresh()->status)->toBe(SellerOrderStatus::SHIPPED);
});

it('tells sellers and buyers about the payout timing and the problem window in the mails', function () {
    $x = reportableOrder('paid');
    $so = $x['sellerOrder']->fresh(['order', 'items']);

    $sale = (string) (new App\Notifications\NewSale($so))->toMail($x['seller'])->render();
    expect($sale)->toContain('14 days after you mark the parcel as shipped')->toContain('waits until that is resolved');

    app(ShipmentService::class)->markShipped($x['seller'], $so, 'UPS', '1Z1');
    $shipped = $so->fresh(['order', 'items']);
    $end = $shipped->shipped_at->copy()->addDays(14)->format('F j, Y');

    $buyerMail = (string) (new App\Notifications\OrderShipped($shipped))->toMail($x['buyer'])->render();
    expect($buyerMail)->toContain('report a problem on your order page until '.$end);

    $this->travel(15)->days();
    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    $paid = $so->fresh(['order', 'items']);
    $payout = (string) (new PayoutSent($paid))->toMail($x['seller'])->render();
    expect($payout)->toContain('released 14 days after you mark the parcel as shipped')->toContain($x['order']->number);

    config(['marketplace.physical_payout_trigger' => 'paid']);
    expect((string) (new App\Notifications\NewSale($so))->toMail($x['seller'])->render())->toContain('released right after the buyer pays');
});

it('keeps the order refund state in step with Stripe after a partial refund made for a problem report', function () {
    config(['marketplace.stripe.marketplace_webhook_secret' => 'whsec_timing']);
    $x = reportableOrder();
    $issue = reportProblem($x);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::PARTIAL_REFUND, 2000);
    issues()->acceptOffer($x['buyer'], $issue->fresh());
    $before = $x['sellerOrder']->fresh();

    // Stripe reports the refund we made ourselves: nothing changes, nothing is refunded twice
    $charge = ['id' => 'ch_x', 'object' => 'charge', 'payment_intent' => $x['order']->stripe_payment_intent_id, 'amount' => 6900, 'amount_refunded' => 2000];
    postStripeEvent($this, 'stripe.marketplace.webhook', stripeEventPayload('charge.refunded', $charge), 'whsec_timing')->assertOk();

    $after = $x['sellerOrder']->fresh();
    expect($after->refunded_cents)->toBe(2000)
        ->and($after->payout_cents)->toBe($before->payout_cents)
        ->and($after->status)->toBe(SellerOrderStatus::SHIPPED)
        ->and(fakeGateway()->callCount('refund'))->toBe(1)
        ->and(fakeGateway()->callCount('reverseTransfer'))->toBe(0);
});
