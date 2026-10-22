<?php

use App\Enums\IssueResolution;
use App\Enums\IssueStatus;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Filament\Resources\OrderResource\RelationManagers\SellerOrdersRelationManager;
use App\Models\User;
use App\Services\Marketplace\RefundService;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| R2.2: the seller's offers (full refund, partial refund, return for a refund), the buyer's answers, and what each path does
| with the money through RefundService: before the payout the payout shrinks (full: it is cancelled), after the payout that
| amount of the transfer is reversed.
|
| The numbers: 6000 items + 900 shipping, fee 600, payout 6300.
*/

beforeEach(function () {
    Storage::fake('private');
    config([
        'marketplace.fee_bps' => 1000, 'marketplace.fee_fixed_cents' => 0,
        'marketplace.physical_payout_trigger' => 'shipped_delay', 'marketplace.physical_payout_delay_days' => 14,
        'marketplace.issue_window_days' => 14, 'marketplace.issue_escalation_days' => 3,
    ]);
});

// --- the offer itself -------------------------------------------------------------------------------------------------

it('lets the seller make an offer, which waits for the buyer', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    $issue = issues()->makeOffer($x['seller'], $issue, IssueResolution::PARTIAL_REFUND, 2000, 'Sorry for the trouble.');

    expect($issue->status)->toBe(IssueStatus::OFFER_PENDING)
        ->and($issue->offer_type)->toBe(IssueResolution::PARTIAL_REFUND)
        ->and($issue->offer_cents)->toBe(2000)
        ->and($issue->offered_at)->not->toBeNull()
        ->and($issue->hasPendingOffer())->toBeTrue()
        ->and($issue->offerLabel())->toBe('a partial refund of $20.00')
        ->and($issue->messages->last()->event)->toBe('offer')
        ->and($issue->messages->last()->author_id)->toBe($x['seller']->id)
        ->and($issue->messages->last()->body)->toContain('Offer: a partial refund of $20.00.')->toContain('Sorry for the trouble.')
        // no money moved yet
        ->and(fakeGateway()->callCount('refund'))->toBe(0)
        ->and($x['buyer']->notifications()->where('data->kind', 'issue_offer')->count())->toBe(1)
        ->and($x['seller']->notifications()->where('data->kind', 'issue_offer')->count())->toBe(0);
});

it('validates offers: type, amount and what is left to refund', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    foreach ([null, 0, -5, 6900, 7000] as $amount) {
        expect(fn () => issues()->makeOffer($x['seller'], $issue, IssueResolution::PARTIAL_REFUND, $amount))
            ->toThrow(InvalidOrderStateException::class);
    }

    expect(fn () => issues()->makeOffer($x['seller'], $issue, IssueResolution::NO_ACTION))->toThrow(ValidationException::class)
        ->and(fn () => issues()->makeOffer($x['seller'], $issue, IssueResolution::WITHDRAWN))->toThrow(ValidationException::class)
        ->and($issue->fresh()->status)->toBe(IssueStatus::OPEN);

    // one cent less than everything is still a partial refund; the amount of a full refund is ignored
    expect(issues()->makeOffer($x['seller'], $issue, IssueResolution::PARTIAL_REFUND, 6899)->offer_cents)->toBe(6899)
        ->and(issues()->makeOffer($x['seller'], $issue, IssueResolution::FULL_REFUND, 1234)->offer_cents)->toBeNull();
});

it('replaces a pending offer with a new one', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    issues()->makeOffer($x['seller'], $issue, IssueResolution::PARTIAL_REFUND, 1000);
    $issue = issues()->makeOffer($x['seller'], $issue->fresh(), IssueResolution::FULL_REFUND);

    expect($issue->offer_type)->toBe(IssueResolution::FULL_REFUND)->and($issue->offer_cents)->toBeNull()
        ->and($issue->messages->where('event', 'offer'))->toHaveCount(2);
});

it('does not allow offers on a resolved, escalated or returning issue, nor a return for digital items', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::RETURN_REFUND);
    issues()->acceptOffer($x['buyer'], $issue->fresh());

    expect(fn () => issues()->makeOffer($x['seller'], $issue->fresh(), IssueResolution::FULL_REFUND))->toThrow(InvalidOrderStateException::class);

    $y = reportableOrder();
    $second = reportProblem($y);
    $this->travel(3)->days();
    issues()->escalate($y['buyer'], $second);
    expect(fn () => issues()->makeOffer($y['seller'], $second->fresh(), IssueResolution::FULL_REFUND))->toThrow(InvalidOrderStateException::class);

    $buyer = User::factory()->create();
    $seller = onboardedSeller();
    $order = pendingOrder($buyer, [paidDesign($seller, 3000)]);
    payOrder($order, 'pi_ret', 'ch_ret');
    $digital = issues()->open($buyer, $order->sellerOrders()->first(), App\Enums\IssueReason::DOWNLOAD_PROBLEM, 'The file is corrupted, I checked twice.');
    expect(fn () => issues()->makeOffer($seller, $digital, IssueResolution::RETURN_REFUND))->toThrow(InvalidOrderStateException::class, 'Digital items cannot be sent back');
});

// --- full refund before the payout ------------------------------------------------------------------------------------

it('refunds in full when the buyer accepts, and cancels the payout that was still pending', function () {
    $x = reportableOrder();
    $so = $x['sellerOrder'];
    $issue = reportProblem($x);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::FULL_REFUND);

    $issue = issues()->acceptOffer($x['buyer'], $issue->fresh());

    $refund = fakeGateway()->lastCall('refund');
    expect($issue->status)->toBe(IssueStatus::RESOLVED)
        ->and($issue->resolution)->toBe(IssueResolution::FULL_REFUND)
        ->and($issue->refunded_cents)->toBe(6900)
        ->and($issue->resolved_at)->not->toBeNull()
        ->and($issue->resolved_by)->toBe($x['buyer']->id)
        // the whole untouched order: one full refund of the payment
        ->and($refund['paymentIntentId'])->toBe($x['order']->stripe_payment_intent_id)
        ->and($refund['amountCents'])->toBeNull()
        ->and($refund['meta']['refunded_by'])->toBe('system')
        ->and(fakeGateway()->callCount('reverseTransfer'))->toBe(0);

    $so->refresh();
    expect($so->status)->toBe(SellerOrderStatus::REFUNDED)
        ->and($so->refunded_cents)->toBe(6900)
        ->and($so->isTransferred())->toBeFalse()
        ->and($x['order']->fresh()->status)->toBe(OrderStatus::REFUNDED);

    // the pending payout is gone for good, now or later
    $this->travel(30)->days();
    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect($so->fresh()->isTransferred())->toBeFalse()->and(fakeGateway()->callCount('createTransfer'))->toBe(0);

    // the seller was told, the buyer (who did it) was not
    expect($x['seller']->notifications()->where('data->kind', 'issue_offer_answered')->count())->toBe(1)
        ->and($x['buyer']->notifications()->where('data->kind', 'issue_resolved')->count())->toBe(0);
});

// --- partial refund before the payout ---------------------------------------------------------------------------------

it('refunds part of it, shrinks the pending payout and keeps the platform fee', function () {
    $x = reportableOrder();
    $so = $x['sellerOrder'];
    $issue = reportProblem($x);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::PARTIAL_REFUND, 2000);

    $issue = issues()->acceptOffer($x['buyer'], $issue->fresh());

    $refund = fakeGateway()->lastCall('refund');
    $so->refresh();
    expect($issue->status)->toBe(IssueStatus::RESOLVED)
        ->and($issue->resolution)->toBe(IssueResolution::PARTIAL_REFUND)
        ->and($issue->refunded_cents)->toBe(2000)
        ->and($refund['amountCents'])->toBe(2000)
        ->and($so->status)->toBe(SellerOrderStatus::SHIPPED)          // the parcel is still the parcel
        ->and($so->refunded_cents)->toBe(2000)
        ->and($so->refundableCents())->toBe(4900)
        ->and($so->platform_fee_cents)->toBe(600)                     // the fee is kept on a partial refund
        ->and($so->payout_cents)->toBe(6300 - 2000)                   // the payout shrinks by the refunded amount
        ->and($so->netPayoutCents())->toBe(4300)
        ->and($so->stripe_refund_id)->toBe('re_fake_1')
        ->and($x['order']->fresh()->status)->toBe(OrderStatus::PARTIALLY_REFUNDED)
        ->and(fakeGateway()->callCount('reverseTransfer'))->toBe(0);

    // and it is paid out in full 14 days after shipping, reduced
    $this->travel(14)->days();
    $this->artisan('marketplace:auto-complete')->assertSuccessful();

    expect(fakeGateway()->callCount('createTransfer'))->toBe(1)
        ->and(fakeGateway()->lastCall('createTransfer')['amountCents'])->toBe(4300)
        ->and($so->fresh()->isTransferred())->toBeTrue()
        ->and($so->fresh()->status)->toBe(SellerOrderStatus::COMPLETED)
        ->and($x['order']->fresh()->status)->toBe(OrderStatus::PARTIALLY_REFUNDED); // stays flagged, never "completed"
});

it('refunds the rest after an earlier partial refund and cancels what is left of the payout', function () {
    $x = reportableOrder();
    $so = $x['sellerOrder'];

    // an admin already refunded 1500 of this seller order
    app(RefundService::class)->refundSellerOrderPartially(adminUser(), $so, 1500, 'goodwill');
    expect($so->fresh()->payout_cents)->toBe(4800)->and($so->fresh()->refundableCents())->toBe(5400);

    $issue = reportProblem($x);
    expect($issue->refundableCents())->toBe(5400);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::FULL_REFUND);
    $issue = issues()->acceptOffer($x['buyer'], $issue->fresh());

    expect($issue->refunded_cents)->toBe(5400)
        ->and(fakeGateway()->lastCall('refund')['amountCents'])->toBe(5400)  // only what was left
        ->and($so->fresh()->refunded_cents)->toBe(6900)
        ->and($so->fresh()->status)->toBe(SellerOrderStatus::REFUNDED)
        ->and($x['order']->fresh()->status)->toBe(OrderStatus::REFUNDED);
});

// --- refunds after the payout -----------------------------------------------------------------------------------------

it('reverses that amount of the transfer when a partial refund comes after the payout', function () {
    config(['marketplace.physical_payout_trigger' => 'shipped']);
    $x = reportableOrder();
    $so = $x['sellerOrder'];
    expect($so->isTransferred())->toBeTrue();

    $issue = reportProblem($x);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::PARTIAL_REFUND, 2000);
    issues()->acceptOffer($x['buyer'], $issue->fresh());

    $reversal = fakeGateway()->lastCall('reverseTransfer');
    $so->refresh();
    expect($reversal['transferId'])->toBe($so->stripe_transfer_id)
        ->and($reversal['amountCents'])->toBe(2000)
        ->and(fakeGateway()->lastCall('refund')['amountCents'])->toBe(2000)
        ->and($so->reversed_cents)->toBe(2000)
        ->and($so->reversal_pending_cents)->toBe(0)
        ->and($so->stripe_reversal_id)->toBe('trr_fake_1')
        ->and($so->payout_cents)->toBe(6300)                // what was transferred stays on record
        ->and($so->netPayoutCents())->toBe(4300)
        ->and($so->status)->toBe(SellerOrderStatus::SHIPPED);
});

it('reverses what is left of the transfer when a full refund comes after the payout', function () {
    config(['marketplace.physical_payout_trigger' => 'shipped']);
    $x = reportableOrder();
    $so = $x['sellerOrder'];

    // a first partial refund took 2000 back already
    $issue = reportProblem($x);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::PARTIAL_REFUND, 2000);
    issues()->acceptOffer($x['buyer'], $issue->fresh());

    $second = reportProblem($x, App\Enums\IssueReason::DAMAGED, 'Now that I look closer, the whole cone is dented too.');
    issues()->makeOffer($x['seller'], $second, IssueResolution::FULL_REFUND);
    issues()->acceptOffer($x['buyer'], $second->fresh());

    $so->refresh();
    expect(fakeGateway()->callCount('reverseTransfer'))->toBe(2)
        ->and(fakeGateway()->lastCall('reverseTransfer')['amountCents'])->toBe(4300) // 6300 - 2000
        ->and(fakeGateway()->lastCall('refund')['amountCents'])->toBe(4900)
        ->and($so->status)->toBe(SellerOrderStatus::REFUNDED)
        ->and($so->refunded_cents)->toBe(6900)
        ->and($so->reversed_cents)->toBe(6300)
        ->and($so->netPayoutCents())->toBe(0);
});

it('takes back a digital seller orders payout and revokes the download on a full refund', function () {
    $buyer = User::factory()->create();
    $seller = onboardedSeller();
    $design = paidDesign($seller, 3000);
    $order = pendingOrder($buyer, [$design]);
    payOrder($order, 'pi_dr', 'ch_dr');
    $so = $order->sellerOrders()->first();
    expect($so->isTransferred())->toBeTrue()->and($so->payout_cents)->toBe(2700);

    $issue = issues()->open($buyer, $so, App\Enums\IssueReason::NOT_AS_DESCRIBED, 'The design is not what the pictures promised.');
    issues()->makeOffer($seller, $issue, IssueResolution::PARTIAL_REFUND, 1000);
    issues()->acceptOffer($buyer, $issue->fresh());

    expect(fakeGateway()->lastCall('reverseTransfer')['amountCents'])->toBe(1000)
        ->and($buyer->hasAccessTo($design, false))->toBeTrue(); // a partial refund keeps the download

    $again = issues()->open($buyer, $so->fresh(), App\Enums\IssueReason::OTHER, 'I would like all my money back please.');
    issues()->makeOffer($seller, $again, IssueResolution::FULL_REFUND);
    issues()->acceptOffer($buyer, $again->fresh());

    expect(fakeGateway()->lastCall('reverseTransfer')['amountCents'])->toBe(1700) // 2700 - 1000
        ->and($buyer->hasAccessTo($design, false))->toBeFalse()
        ->and($so->fresh()->status)->toBe(SellerOrderStatus::REFUNDED);
});

it('records a transfer reversal Stripe refused and lets an admin retry it', function () {
    config(['marketplace.physical_payout_trigger' => 'shipped']);
    $x = reportableOrder();
    $so = $x['sellerOrder'];
    $issue = reportProblem($x);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::PARTIAL_REFUND, 2000);

    fakeGateway()->failOn('reverseTransfer');
    $issue = issues()->acceptOffer($x['buyer'], $issue->fresh());

    // the buyer is refunded and the issue is resolved anyway; the reversal is owed
    $so->refresh();
    expect($issue->status)->toBe(IssueStatus::RESOLVED)
        ->and($so->refunded_cents)->toBe(2000)
        ->and($so->reversed_cents)->toBe(0)
        ->and($so->reversal_pending_cents)->toBe(2000)
        ->and(SellerOrdersRelationManager::needsReversal($so))->toBeTrue();

    $admin = adminUser();
    expect(app(RefundService::class)->retryTransferReversal($admin, $so))->toBeTrue();
    $so->refresh();
    expect($so->reversed_cents)->toBe(2000)->and($so->reversal_pending_cents)->toBe(0)
        ->and(fakeGateway()->lastCall('reverseTransfer')['amountCents'])->toBe(2000)
        ->and(SellerOrdersRelationManager::needsReversal($so))->toBeFalse()
        ->and(app(RefundService::class)->retryTransferReversal($admin, $so))->toBeFalse();
});

// --- return for a refund ----------------------------------------------------------------------------------------------

it('runs a return for a refund end to end: accept, tracking, confirm, full refund', function () {
    $x = reportableOrder();
    $so = $x['sellerOrder'];
    $issue = reportProblem($x, App\Enums\IssueReason::WRONG_ITEM, 'I got a different model than the one I ordered.');
    issues()->makeOffer($x['seller'], $issue, IssueResolution::RETURN_REFUND, null, 'Send it back at my cost.');

    $issue = issues()->acceptOffer($x['buyer'], $issue->fresh());
    expect($issue->status)->toBe(IssueStatus::AWAITING_RETURN)
        ->and($issue->offer_type)->toBe(IssueResolution::RETURN_REFUND)
        ->and(fakeGateway()->callCount('refund'))->toBe(0)
        ->and($x['seller']->notifications()->where('data->kind', 'issue_offer_answered')->count())->toBe(1);

    // the seller cannot confirm what was not sent
    expect(fn () => issues()->confirmReturnReceived($x['seller'], $issue))->toThrow(InvalidOrderStateException::class, 'has not sent anything back');

    expect(fn () => issues()->addReturnTracking($x['buyer'], $issue, '', '1Z'))->toThrow(ValidationException::class)
        ->and(fn () => issues()->addReturnTracking($x['buyer'], $issue, 'UPS', ''))->toThrow(ValidationException::class)
        ->and(fn () => issues()->addReturnTracking($x['buyer'], $issue, 'UPS', '1Z', 'javascript:alert(1)'))->toThrow(ValidationException::class);

    $issue = issues()->addReturnTracking($x['buyer'], $issue, 'USPS', '9400111', 'https://usps.example/9400111');
    expect($issue->return_carrier)->toBe('USPS')
        ->and($issue->return_tracking_number)->toBe('9400111')
        ->and($issue->return_tracking_url)->toBe('https://usps.example/9400111')
        ->and($issue->return_shipped_at)->not->toBeNull()
        ->and($issue->status)->toBe(IssueStatus::AWAITING_RETURN)
        ->and($x['seller']->notifications()->where('data->kind', 'issue_message')->count())->toBe(1);

    // the tracking can be corrected
    $issue = issues()->addReturnTracking($x['buyer'], $issue, 'USPS', '9400222');
    expect($issue->return_tracking_number)->toBe('9400222');

    $issue = issues()->confirmReturnReceived($x['seller'], $issue);

    expect($issue->status)->toBe(IssueStatus::RESOLVED)
        ->and($issue->resolution)->toBe(IssueResolution::RETURN_REFUND)
        ->and($issue->refunded_cents)->toBe(6900)
        ->and($issue->return_received_at)->not->toBeNull()
        ->and($issue->resolved_by)->toBe($x['seller']->id)
        ->and(fakeGateway()->lastCall('refund')['amountCents'])->toBeNull()
        ->and($so->fresh()->status)->toBe(SellerOrderStatus::REFUNDED)
        ->and($so->fresh()->isTransferred())->toBeFalse()
        ->and($x['buyer']->notifications()->where('data->kind', 'issue_resolved')->count())->toBe(1)
        ->and($x['seller']->notifications()->where('data->kind', 'issue_resolved')->count())->toBe(0);
});

it('does not take return tracking before a return was agreed', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    expect(fn () => issues()->addReturnTracking($x['buyer'], $issue, 'UPS', '1Z'))->toThrow(InvalidOrderStateException::class);

    issues()->makeOffer($x['seller'], $issue, IssueResolution::RETURN_REFUND);
    expect(fn () => issues()->addReturnTracking($x['buyer'], $issue->fresh(), 'UPS', '1Z'))->toThrow(InvalidOrderStateException::class); // offered, not accepted
});

// --- declining, withdrawing, failing ----------------------------------------------------------------------------------

it('lets the buyer decline an offer: the issue is open again and the seller can try something else', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::PARTIAL_REFUND, 500);

    $issue = issues()->declineOffer($x['buyer'], $issue->fresh(), 'That is too little for a cracked cone.');

    expect($issue->status)->toBe(IssueStatus::OPEN)
        ->and($issue->offer_type)->toBeNull()->and($issue->offer_cents)->toBeNull()->and($issue->offered_at)->toBeNull()
        ->and($issue->messages->last()->event)->toBe('offer_declined')
        ->and($issue->messages->last()->body)->toContain('Declined the offer of a partial refund of $5.00.')->toContain('too little')
        ->and(fakeGateway()->callCount('refund'))->toBe(0)
        ->and($x['seller']->notifications()->where('data->kind', 'issue_offer_answered')->count())->toBe(1);

    expect(fn () => issues()->declineOffer($x['buyer'], $issue))->toThrow(InvalidOrderStateException::class, 'no offer')
        ->and(fn () => issues()->acceptOffer($x['buyer'], $issue))->toThrow(InvalidOrderStateException::class, 'no offer');

    $issue = issues()->makeOffer($x['seller'], $issue, IssueResolution::PARTIAL_REFUND, 3000);
    expect($issue->status)->toBe(IssueStatus::OFFER_PENDING)->and($issue->offer_cents)->toBe(3000);
});

it('lets the buyer withdraw: nothing is refunded, the seller is told and the payout goes on', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    $issue = issues()->withdraw($x['buyer'], $issue);

    expect($issue->status)->toBe(IssueStatus::RESOLVED)
        ->and($issue->resolution)->toBe(IssueResolution::WITHDRAWN)
        ->and($issue->refunded_cents)->toBe(0)
        ->and($issue->resolved_by)->toBe($x['buyer']->id)
        ->and(fakeGateway()->callCount('refund'))->toBe(0)
        ->and($x['seller']->notifications()->where('data->kind', 'issue_resolved')->count())->toBe(1);

    expect(fn () => issues()->withdraw($x['buyer'], $issue))->toThrow(InvalidOrderStateException::class)
        ->and(fn () => issues()->reply($x['buyer'], $issue, 'one more thing'))->toThrow(InvalidOrderStateException::class, 'resolved');
});

it('also lets the buyer withdraw while an offer is pending or the goods are on their way back', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::RETURN_REFUND);
    issues()->acceptOffer($x['buyer'], $issue->fresh());

    expect(issues()->withdraw($x['buyer'], $issue->fresh())->resolution)->toBe(IssueResolution::WITHDRAWN);
});

it('leaves the issue and the money alone when Stripe refuses the refund, and works on a retry', function () {
    $x = reportableOrder();
    $so = $x['sellerOrder'];
    $issue = reportProblem($x);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::FULL_REFUND);

    fakeGateway()->failOn('refund');
    expect(fn () => issues()->acceptOffer($x['buyer'], $issue->fresh()))->toThrow(PaymentGatewayException::class);

    expect($issue->fresh()->status)->toBe(IssueStatus::OFFER_PENDING)
        ->and($issue->fresh()->resolution)->toBeNull()
        ->and($issue->fresh()->refunded_cents)->toBe(0)
        ->and($so->fresh()->status)->toBe(SellerOrderStatus::SHIPPED)
        ->and($so->fresh()->refunded_cents)->toBe(0)
        ->and($x['order']->fresh()->status)->toBe(OrderStatus::PAID);

    expect(issues()->acceptOffer($x['buyer'], $issue->fresh())->status)->toBe(IssueStatus::RESOLVED);
});

it('keeps buyer and seller out of each others actions: 403', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    $stranger = User::factory()->create();
    issues()->makeOffer($x['seller'], $issue, IssueResolution::FULL_REFUND);
    $issue = $issue->fresh();

    expect(fn () => issues()->makeOffer($x['buyer'], $issue, IssueResolution::FULL_REFUND))->toThrow(AuthorizationException::class)
        ->and(fn () => issues()->makeOffer(adminUser(), $issue, IssueResolution::FULL_REFUND))->toThrow(AuthorizationException::class)
        ->and(fn () => issues()->makeOffer($stranger, $issue, IssueResolution::FULL_REFUND))->toThrow(AuthorizationException::class);

    foreach ([$x['seller'], adminUser(), $stranger] as $nobody) {
        expect(fn () => issues()->acceptOffer($nobody, $issue))->toThrow(AuthorizationException::class)
            ->and(fn () => issues()->declineOffer($nobody, $issue))->toThrow(AuthorizationException::class)
            ->and(fn () => issues()->withdraw($nobody, $issue))->toThrow(AuthorizationException::class)
            ->and(fn () => issues()->addReturnTracking($nobody, $issue, 'UPS', '1Z'))->toThrow(AuthorizationException::class);
    }

    foreach ([$x['buyer'], adminUser(), $stranger] as $nobody) {
        expect(fn () => issues()->confirmReturnReceived($nobody, $issue))->toThrow(AuthorizationException::class);
    }

    expect($issue->fresh()->status)->toBe(IssueStatus::OFFER_PENDING)->and(fakeGateway()->callCount('refund'))->toBe(0);
});

it('shows only the actions a person may take right now', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    $admin = adminUser();

    expect(issues()->can($x['buyer'], $issue, 'reply'))->toBeTrue()
        ->and(issues()->can($x['buyer'], $issue, 'withdraw'))->toBeTrue()
        ->and(issues()->can($x['buyer'], $issue, 'acceptOffer'))->toBeFalse()
        ->and(issues()->can($x['buyer'], $issue, 'escalate'))->toBeFalse() // too early
        ->and(issues()->can($x['seller'], $issue, 'makeOffer'))->toBeTrue()
        ->and(issues()->can($x['seller'], $issue, 'confirmReturnReceived'))->toBeFalse()
        ->and(issues()->can($admin, $issue, 'decide'))->toBeTrue()
        ->and(issues()->can($admin, $issue, 'internalNote'))->toBeTrue()
        ->and(issues()->can($admin, $issue, 'makeOffer'))->toBeFalse()
        ->and(issues()->can($admin, $issue, 'recover'))->toBeFalse()
        ->and(issues()->can(User::factory()->create(), $issue, 'reply'))->toBeFalse();

    issues()->makeOffer($x['seller'], $issue, IssueResolution::FULL_REFUND);
    $issue = $issue->fresh();

    expect(issues()->can($x['buyer'], $issue, 'acceptOffer'))->toBeTrue()
        ->and(issues()->can($x['buyer'], $issue, 'declineOffer'))->toBeTrue()
        ->and(issues()->can($x['seller'], $issue, 'acceptOffer'))->toBeFalse();

    $this->travel(3)->days();
    expect(issues()->can($x['buyer'], $issue, 'escalate'))->toBeTrue()
        ->and(issues()->can($x['seller'], $issue, 'escalate'))->toBeTrue()
        ->and(issues()->can($admin, $issue, 'escalate'))->toBeFalse();
});
