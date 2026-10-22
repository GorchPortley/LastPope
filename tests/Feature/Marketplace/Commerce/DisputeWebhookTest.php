<?php

use App\Enums\IssueReason;
use App\Enums\IssueResolution;
use App\Enums\IssueStatus;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Models\OrderIssue;
use App\Models\StripeEvent;
use App\Models\User;
use App\Notifications\DisputeOpened;
use App\Notifications\IssueEscalated;
use App\Notifications\IssueResolved;
use App\Services\Marketplace\ShipmentService;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| R2.2: Stripe bank disputes on the marketplace webhook. charge.dispute.created opens (or joins) an ESCALATED issue
| per seller order and holds their payouts; charge.dispute.closed records the outcome; "Recover from seller" takes the money
| back after a lost dispute.
*/

beforeEach(function () {
    $this->issuesBefore = OrderIssue::count(); // the demo data has problem reports of its own
    Storage::fake('private');
    config([
        'marketplace.stripe.marketplace_webhook_secret' => 'whsec_marketplace_test',
        'marketplace.fee_bps' => 1000, 'marketplace.fee_fixed_cents' => 0,
        'marketplace.physical_payout_trigger' => 'shipped_delay', 'marketplace.physical_payout_delay_days' => 14,
    ]);
});

/**
 * A paid two-seller order: a 30.00 design from seller A (paid out at payment: 27.00) and a 60.00 listing + 9.00 shipping
 * from seller B (shipped, payout of 63.00 due in 14 days).
 */
function disputedOrder(): array
{
    $buyer = User::factory()->create();
    $sellerA = onboardedSeller();
    $sellerB = onboardedSeller();
    $design = paidDesign($sellerA, 3000);
    $listing = sellableListing($sellerB, 6000, ['shipping_domestic_cents' => 900, 'quantity' => 3]);
    $order = pendingOrder($buyer, [$design, $listing], addressFor($buyer));
    payOrder($order, 'pi_disp', 'ch_disp');
    $soA = $order->sellerOrders()->where('seller_id', $sellerA->id)->first();
    $soB = $order->sellerOrders()->where('seller_id', $sellerB->id)->first();
    app(ShipmentService::class)->markShipped($sellerB, $soB, 'UPS', '1ZDISPUTE');

    return compact('buyer', 'sellerA', 'sellerB', 'order', 'soA', 'soB') + ['order' => $order->fresh()];
}

function disputeObject(array $extra = []): array
{
    return $extra + [
        'id' => 'dp_test_1',
        'object' => 'dispute',
        'amount' => 6900,
        'currency' => 'usd',
        'charge' => 'ch_disp',
        'payment_intent' => 'pi_disp',
        'reason' => 'fraudulent',
        'status' => 'needs_response',
        'evidence_details' => ['due_by' => now()->addDays(7)->timestamp],
    ];
}

function disputeHook(object $test, string $type, array $dispute, ?string $secret = 'whsec_marketplace_test', ?string $signature = null)
{
    return postStripeEvent($test, 'stripe.marketplace.webhook', stripeEventPayload($type, $dispute), $secret, $signature);
}

// --- charge.dispute.created ---------------------------------------------------------------------------------------------

it('opens an escalated issue for every seller order, holds the payouts and tells the admins and the sellers', function () {
    $admin = adminUser();
    $x = disputedOrder();
    Notification::fake();

    disputeHook($this, 'charge.dispute.created', disputeObject())->assertOk()->assertJson(['status' => 'handled']);

    $issues = OrderIssue::where('order_id', $x['order']->id)->orderBy('seller_order_id')->get();
    expect($issues)->toHaveCount(2);

    foreach ($issues as $issue) {
        expect($issue->status)->toBe(IssueStatus::ESCALATED)
            ->and($issue->stripe_dispute_id)->toBe('dp_test_1')
            ->and($issue->dispute_status)->toBe('needs_response')
            ->and($issue->dispute_reason)->toBe('fraudulent')
            ->and($issue->dispute_due_at)->not->toBeNull()
            ->and($issue->escalated_at)->not->toBeNull()
            ->and($issue->escalated_by)->toBeNull()
            ->and($issue->buyer_id)->toBe($x['buyer']->id)
            ->and($issue->reason)->toBe(IssueReason::OTHER)
            ->and($issue->isDispute())->toBeTrue()
            ->and($issue->messages)->toHaveCount(1)
            ->and($issue->messages->first()->isSystem())->toBeTrue()
            ->and($issue->messages->first()->event)->toBe('dispute')
            ->and($issue->messages->first()->body)->toContain('$69.00')->toContain('fraudulent');
    }

    expect($issues->pluck('seller_id')->sort()->values()->all())->toBe(collect([$x['sellerA']->id, $x['sellerB']->id])->sort()->values()->all());

    Notification::assertSentTo($admin, DisputeOpened::class, 1);
    Notification::assertSentTo($x['sellerA'], IssueEscalated::class, 1);
    Notification::assertSentTo($x['sellerB'], IssueEscalated::class, 1);
    Notification::assertNotSentTo($x['buyer'], IssueEscalated::class);
    Notification::assertNotSentTo($x['buyer'], DisputeOpened::class);

    // seller B's payout is on hold, whatever the date
    $this->travel(20)->days();
    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect($x['soB']->fresh()->isTransferred())->toBeFalse();
});

it('does not repeat itself when Stripe delivers the dispute twice', function () {
    $x = disputedOrder();
    $event = stripeEventPayload('charge.dispute.created', disputeObject());

    postStripeEvent($this, 'stripe.marketplace.webhook', $event, 'whsec_marketplace_test')->assertOk()->assertJson(['status' => 'handled']);
    postStripeEvent($this, 'stripe.marketplace.webhook', $event, 'whsec_marketplace_test')->assertOk()->assertJson(['status' => 'duplicate']);
    // and a second event about the very same dispute does not add issues either
    disputeHook($this, 'charge.dispute.created', disputeObject())->assertOk();

    expect(OrderIssue::where('order_id', $x['order']->id)->count())->toBe(2)
        ->and(OrderIssue::where('order_id', $x['order']->id)->first()->messages()->count())->toBe(1)
        ->and(StripeEvent::count())->toBe(2);
});

it('joins an issue that the buyer opened and escalates it', function () {
    $x = disputedOrder();
    $mine = issues()->open($x['buyer'], $x['soB']->fresh(), IssueReason::NOT_RECEIVED, 'The parcel did not arrive yet, tracking is stuck.');

    disputeHook($this, 'charge.dispute.created', disputeObject())->assertOk();

    expect(OrderIssue::where('seller_order_id', $x['soB']->id)->count())->toBe(1)
        ->and(OrderIssue::where('seller_order_id', $x['soA']->id)->count())->toBe(1);

    $mine->refresh();
    expect($mine->status)->toBe(IssueStatus::ESCALATED)
        ->and($mine->reason)->toBe(IssueReason::NOT_RECEIVED) // the buyer's own reason stays
        ->and($mine->stripe_dispute_id)->toBe('dp_test_1')
        ->and($mine->messages)->toHaveCount(2)
        ->and($mine->messages->last()->event)->toBe('dispute');
});

it('opens a new issue when the earlier one is resolved', function () {
    $x = disputedOrder();
    $old = issues()->open($x['buyer'], $x['soB']->fresh(), IssueReason::OTHER, 'Something odd about this order really.');
    issues()->withdraw($x['buyer'], $old);

    disputeHook($this, 'charge.dispute.created', disputeObject())->assertOk();

    expect(OrderIssue::where('seller_order_id', $x['soB']->id)->count())->toBe(2)
        ->and($old->fresh()->stripe_dispute_id)->toBeNull()
        ->and($x['soB']->fresh()->activeIssue->stripe_dispute_id)->toBe('dp_test_1');
});

it('finds the order by the charge id when the dispute carries no payment intent', function () {
    $x = disputedOrder();

    disputeHook($this, 'charge.dispute.created', disputeObject(['payment_intent' => null]))->assertOk()->assertJson(['status' => 'handled']);

    expect(OrderIssue::where('order_id', $x['order']->id)->count())->toBe(2);
});

it('ignores disputes of charges that are no marketplace orders (Wave subscriptions)', function () {
    disputedOrder();

    disputeHook($this, 'charge.dispute.created', disputeObject(['id' => 'dp_other', 'charge' => 'ch_subscription', 'payment_intent' => 'pi_subscription']))
        ->assertOk()->assertJson(['status' => 'ignored']);
    disputeHook($this, 'charge.dispute.closed', disputeObject(['id' => 'dp_other', 'charge' => 'ch_subscription', 'payment_intent' => 'pi_subscription', 'status' => 'lost']))
        ->assertOk()->assertJson(['status' => 'ignored']);

    expect((OrderIssue::count() - $this->issuesBefore))->toBe(0);
});

it('rejects a dispute event with a bad signature', function () {
    $x = disputedOrder();

    disputeHook($this, 'charge.dispute.created', disputeObject(), signature: 'garbage')->assertStatus(400);
    disputeHook($this, 'charge.dispute.created', disputeObject(), secret: 'whsec_other')->assertStatus(400);

    expect((OrderIssue::count() - $this->issuesBefore))->toBe(0);
});

// --- charge.dispute.closed ----------------------------------------------------------------------------------------------

it('records a won dispute and lets the payouts go on', function () {
    $admin = adminUser();
    $x = disputedOrder();
    disputeHook($this, 'charge.dispute.created', disputeObject())->assertOk();
    Notification::fake();

    disputeHook($this, 'charge.dispute.closed', disputeObject(['status' => 'won']))->assertOk()->assertJson(['status' => 'handled']);

    $issues = OrderIssue::where('order_id', $x['order']->id)->get();
    foreach ($issues as $issue) {
        expect($issue->status)->toBe(IssueStatus::RESOLVED)
            ->and($issue->resolution)->toBe(IssueResolution::CHARGEBACK_WON)
            ->and($issue->dispute_status)->toBe('won')
            ->and($issue->resolved_at)->not->toBeNull()
            ->and($issue->resolved_by)->toBeNull()
            ->and($issue->messages->last()->body)->toContain('decided in our favour');
    }

    Notification::assertSentTo($admin, IssueResolved::class);
    Notification::assertSentTo($x['sellerB'], IssueResolved::class, 1);
    Notification::assertNotSentTo($x['buyer'], IssueResolved::class);

    // the hold is over: seller B is paid 14 days after shipping
    $this->travel(15)->days();
    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect($x['soB']->fresh()->isTransferred())->toBeTrue();
});

it('records a lost dispute and keeps the payout blocked until an admin decides', function () {
    $x = disputedOrder();
    disputeHook($this, 'charge.dispute.created', disputeObject())->assertOk();

    disputeHook($this, 'charge.dispute.closed', disputeObject(['status' => 'lost']))->assertOk()->assertJson(['status' => 'handled']);

    $issues = OrderIssue::where('order_id', $x['order']->id)->get();
    foreach ($issues as $issue) {
        expect($issue->status)->toBe(IssueStatus::RESOLVED)
            ->and($issue->resolution)->toBe(IssueResolution::CHARGEBACK_LOST)
            ->and($issue->dispute_status)->toBe('lost')
            ->and($issue->recovered_at)->toBeNull();
    }

    $this->travel(20)->days();
    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect($x['soB']->fresh()->isTransferred())->toBeFalse()
        ->and(fakeGateway()->callCount('reverseTransfer'))->toBe(0); // nothing is taken back automatically
});

it('maps the other closing statuses', function (string $status, IssueResolution $expected) {
    $x = disputedOrder();
    disputeHook($this, 'charge.dispute.created', disputeObject())->assertOk();

    disputeHook($this, 'charge.dispute.closed', disputeObject(['status' => $status]))->assertOk();

    expect(OrderIssue::where('order_id', $x['order']->id)->pluck('resolution')->unique()->all())->toBe([$expected]);
})->with([
    'inquiry closed' => ['warning_closed', IssueResolution::CHARGEBACK_WON],
    'refunded meanwhile' => ['charge_refunded', IssueResolution::NO_ACTION],
]);

it('handles a closed dispute whose opening was never delivered', function () {
    $x = disputedOrder();

    disputeHook($this, 'charge.dispute.closed', disputeObject(['status' => 'lost']))->assertOk()->assertJson(['status' => 'handled']);

    $issues = OrderIssue::where('order_id', $x['order']->id)->get();
    expect($issues)->toHaveCount(2)
        ->and($issues->every(fn ($i) => $i->resolution === IssueResolution::CHARGEBACK_LOST && $i->status === IssueStatus::RESOLVED))->toBeTrue();
});

it('records the outcome once even when Stripe sends a second closing event', function () {
    $admin = adminUser();
    $x = disputedOrder();
    disputeHook($this, 'charge.dispute.created', disputeObject())->assertOk();
    disputeHook($this, 'charge.dispute.closed', disputeObject(['status' => 'won']))->assertOk();
    Notification::fake();

    disputeHook($this, 'charge.dispute.closed', disputeObject(['status' => 'lost']))->assertOk();

    expect(OrderIssue::where('order_id', $x['order']->id)->pluck('resolution')->unique()->all())->toBe([IssueResolution::CHARGEBACK_WON]);
    Notification::assertNothingSent();
});

// --- recover from seller ------------------------------------------------------------------------------------------------

it('reverses the transfer of a seller who was paid, and cancels the payout of one who was not', function () {
    $admin = adminUser();
    $x = disputedOrder();
    disputeHook($this, 'charge.dispute.created', disputeObject())->assertOk();
    disputeHook($this, 'charge.dispute.closed', disputeObject(['status' => 'lost']))->assertOk();
    $issueA = OrderIssue::where('seller_order_id', $x['soA']->id)->first();
    $issueB = OrderIssue::where('seller_order_id', $x['soB']->id)->first();
    expect($x['soA']->fresh()->isTransferred())->toBeTrue()->and($x['soA']->payout_cents)->toBe(2700);

    // seller A was paid at payment: that money is taken back
    $recovered = issues()->recoverFromSeller($admin, $issueA);

    $reversal = fakeGateway()->lastCall('reverseTransfer');
    expect($recovered->recovered_cents)->toBe(2700)
        ->and($recovered->recovered_at)->not->toBeNull()
        ->and($reversal['transferId'])->toBe($x['soA']->fresh()->stripe_transfer_id)
        ->and($reversal['amountCents'])->toBe(2700)
        ->and($x['soA']->fresh()->reversed_cents)->toBe(2700)
        ->and($x['soA']->fresh()->netPayoutCents())->toBe(0)
        ->and($recovered->messages->last()->event)->toBe('recovered')
        ->and($x['sellerA']->notifications()->where('data->kind', 'issue_message')->count())->toBe(1);

    // seller B was not paid yet: the payout is cancelled instead
    $recoveredB = issues()->recoverFromSeller($admin, $issueB);
    expect($recoveredB->recovered_cents)->toBe(6300)
        ->and($x['soB']->fresh()->payout_cents)->toBe(0)
        ->and(fakeGateway()->callCount('reverseTransfer'))->toBe(1);

    $this->travel(20)->days();
    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect($x['soB']->fresh()->isTransferred())->toBeFalse();

    // only once
    expect(fn () => issues()->recoverFromSeller($admin, $issueA->fresh()))->toThrow(InvalidOrderStateException::class, 'already');
});

it('only recovers after a lost dispute, for an admin, from a real seller', function () {
    $admin = adminUser();
    $x = disputedOrder();
    disputeHook($this, 'charge.dispute.created', disputeObject())->assertOk();
    $issue = OrderIssue::where('seller_order_id', $x['soA']->id)->first();

    // dispute still open
    expect(fn () => issues()->recoverFromSeller($admin, $issue))->toThrow(InvalidOrderStateException::class, 'lost bank dispute');

    disputeHook($this, 'charge.dispute.closed', disputeObject(['status' => 'won']))->assertOk();
    expect(fn () => issues()->recoverFromSeller($admin, $issue->fresh()))->toThrow(InvalidOrderStateException::class, 'lost bank dispute');

    foreach ([$x['buyer'], $x['sellerA'], User::factory()->create()] as $nobody) {
        expect(fn () => issues()->recoverFromSeller($nobody, $issue->fresh()))->toThrow(AuthorizationException::class);
    }

    // SDLabs as the seller: nobody to take money from
    $store = storeListing();
    $buyer = User::factory()->create();
    $order = pendingOrder($buyer, [$store], addressFor($buyer));
    payOrder($order, 'pi_store_d', 'ch_store_d');
    disputeHook($this, 'charge.dispute.closed', disputeObject(['id' => 'dp_store', 'charge' => 'ch_store_d', 'payment_intent' => 'pi_store_d', 'status' => 'lost']))->assertOk();
    $storeIssue = OrderIssue::where('order_id', $order->id)->sole();
    expect(fn () => issues()->recoverFromSeller($admin, $storeIssue))->toThrow(InvalidOrderStateException::class, 'nobody to recover');
    expect(issues()->can($admin, $storeIssue, 'recover'))->toBeFalse();
});

it('keeps the dispute lost and unrecovered when Stripe refuses the reversal', function () {
    $admin = adminUser();
    $x = disputedOrder();
    disputeHook($this, 'charge.dispute.closed', disputeObject(['status' => 'lost']))->assertOk();
    $issueA = OrderIssue::where('seller_order_id', $x['soA']->id)->first();

    fakeGateway()->failOn('reverseTransfer');
    expect(fn () => issues()->recoverFromSeller($admin, $issueA))->toThrow(PaymentGatewayException::class);

    expect($issueA->fresh()->recovered_at)->toBeNull()
        ->and($x['soA']->fresh()->reversed_cents)->toBe(0);

    expect(issues()->recoverFromSeller($admin, $issueA->fresh())->recovered_cents)->toBe(2700);
});
