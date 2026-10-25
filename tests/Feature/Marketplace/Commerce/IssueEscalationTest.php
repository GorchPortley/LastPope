<?php

use App\Enums\IssueResolution;
use App\Enums\IssueStatus;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Models\User;
use App\Notifications\IssueEscalated;
use App\Notifications\IssueMessagePosted;
use App\Notifications\IssueResolved;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| R2.2: the thread, escalation after issue_escalation_days (3) without agreement, and the admins' decisions.
*/

beforeEach(function () {
    Storage::fake('private');
    config([
        'marketplace.fee_bps' => 1000, 'marketplace.fee_fixed_cents' => 0,
        'marketplace.physical_payout_trigger' => 'shipped_delay', 'marketplace.physical_payout_delay_days' => 14,
        'marketplace.issue_window_days' => 14, 'marketplace.issue_escalation_days' => 3,
    ]);
});

// --- the thread -------------------------------------------------------------------------------------------------------

it('lets buyer, seller and admins write in the thread, and only admins write internal notes', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    $admin = adminUser();

    $a = issues()->reply($x['seller'], $issue, 'Can you send a picture of the damage?');
    $b = issues()->reply($x['buyer'], $issue, 'Sure, here you go.', [issuePhoto()]);
    $c = issues()->reply($admin, $issue, 'The team is following along.');
    $note = issues()->internalNote($admin, $issue, 'Seller has two earlier complaints.');

    $issue->refresh();
    expect($issue->messages)->toHaveCount(5)
        ->and($issue->messages->pluck('id')->all())->toBe([$issue->messages->first()->id, $a->id, $b->id, $c->id, $note->id])
        ->and($note->internal)->toBeTrue()
        ->and($note->author_id)->toBe($admin->id)
        ->and($b->files())->toHaveCount(1)
        ->and($issue->publicMessages()->count())->toBe(4)
        ->and($issue->status)->toBe(IssueStatus::OPEN); // talking does not change the status

    expect(fn () => issues()->reply($x['buyer'], $issue, ''))->toThrow(ValidationException::class)
        ->and(fn () => issues()->reply($x['buyer'], $issue, str_repeat('a', 5001)))->toThrow(ValidationException::class)
        ->and(fn () => issues()->internalNote($admin, $issue, ''))->toThrow(ValidationException::class);
});

it('allows a photo without text', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    $message = issues()->reply($x['buyer'], $issue, '', [issuePhoto('a.jpg'), issuePhoto('b.png')]);

    expect($message->body)->toBe('Added 2 photos.')->and($message->files())->toHaveCount(2);
});

it('keeps strangers out of the thread: 403', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    foreach ([User::factory()->create(), onboardedSeller()] as $stranger) {
        expect(fn () => issues()->reply($stranger, $issue, 'Hello?'))->toThrow(AuthorizationException::class)
            ->and(fn () => issues()->internalNote($stranger, $issue, 'Hello?'))->toThrow(AuthorizationException::class);
    }

    foreach ([$x['buyer'], $x['seller']] as $party) {
        expect(fn () => issues()->internalNote($party, $issue, 'sneaky'))->toThrow(AuthorizationException::class);
    }

    expect($issue->fresh()->messages)->toHaveCount(1);
});

it('lets an admin add an internal note to a resolved issue but not reply to it', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    issues()->withdraw($x['buyer'], $issue);
    $admin = adminUser();

    expect(fn () => issues()->reply($admin, $issue->fresh(), 'late reply'))->toThrow(InvalidOrderStateException::class)
        ->and(issues()->internalNote($admin, $issue->fresh(), 'for the record')->internal)->toBeTrue();
});

// --- escalation -------------------------------------------------------------------------------------------------------

it('lets either side escalate once 3 days passed without agreement, not before', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    expect(fn () => issues()->escalate($x['buyer'], $issue))->toThrow(InvalidOrderStateException::class, 'to agree')
        ->and(fn () => issues()->escalate($x['seller'], $issue))->toThrow(InvalidOrderStateException::class, 'to agree')
        ->and($issue->fresh()->status)->toBe(IssueStatus::OPEN);

    $this->travelTo($issue->created_at->copy()->addDays(3)->subSecond());
    expect(issues()->can($x['buyer'], $issue->fresh(), 'escalate'))->toBeFalse();

    $this->travelTo($issue->created_at->copy()->addDays(3));
    expect(issues()->can($x['buyer'], $issue->fresh(), 'escalate'))->toBeTrue();

    $escalated = issues()->escalate($x['buyer'], $issue->fresh(), 'The seller stopped answering.');

    expect($escalated->status)->toBe(IssueStatus::ESCALATED)
        ->and($escalated->escalated_at)->not->toBeNull()
        ->and($escalated->escalated_by)->toBe($x['buyer']->id)
        ->and($escalated->messages->last()->event)->toBe('escalated')
        ->and($escalated->messages->last()->body)->toContain('The seller stopped answering.');
});

it('lets the seller escalate too, and counts an unanswered offer as no agreement', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::FULL_REFUND);

    $this->travel(3)->days();
    $escalated = issues()->escalate($x['seller'], $issue->fresh());

    expect($escalated->status)->toBe(IssueStatus::ESCALATED)->and($escalated->escalated_by)->toBe($x['seller']->id);
});

it('lets either side escalate while waiting for a return that never comes', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::RETURN_REFUND);
    issues()->acceptOffer($x['buyer'], $issue->fresh());

    $this->travel(3)->days();

    expect(issues()->escalate($x['seller'], $issue->fresh())->status)->toBe(IssueStatus::ESCALATED);
});

it('escalates only once and not when resolved, and only for the parties', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    $this->travel(3)->days();

    foreach ([adminUser(), User::factory()->create()] as $nobody) {
        expect(fn () => issues()->escalate($nobody, $issue->fresh()))->toThrow(AuthorizationException::class);
    }

    issues()->escalate($x['buyer'], $issue->fresh());
    expect(fn () => issues()->escalate($x['seller'], $issue->fresh()))->toThrow(InvalidOrderStateException::class, 'already with');

    issues()->adminResolve(adminUser(), $issue->fresh(), IssueResolution::NO_ACTION);
    expect(fn () => issues()->escalate($x['seller'], $issue->fresh()))->toThrow(InvalidOrderStateException::class, 'resolved already');
});

it('keeps the thread open after escalation but the seller can no longer offer', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    $this->travel(3)->days();
    issues()->escalate($x['buyer'], $issue);

    expect(issues()->reply($x['seller'], $issue->fresh(), 'Here is my side of the story.')->body)->toContain('my side')
        ->and(issues()->reply($x['buyer'], $issue->fresh(), 'And a photo.', [issuePhoto()])->files())->toHaveCount(1)
        ->and(fn () => issues()->makeOffer($x['seller'], $issue->fresh(), IssueResolution::FULL_REFUND))->toThrow(InvalidOrderStateException::class)
        ->and(fn () => issues()->withdraw($x['seller'], $issue->fresh()))->toThrow(AuthorizationException::class);
});

it('still lets the buyer withdraw an escalated issue (no dispute)', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    $this->travel(3)->days();
    issues()->escalate($x['seller'], $issue);

    expect(issues()->withdraw($x['buyer'], $issue->fresh())->resolution)->toBe(IssueResolution::WITHDRAWN);
});

// --- admin decisions --------------------------------------------------------------------------------------------------

function escalatedIssue(): array
{
    $x = reportableOrder();
    $issue = reportProblem($x);
    test()->travel(3)->days();
    issues()->escalate($x['buyer'], $issue);

    return $x + ['issue' => $issue->fresh(), 'admin' => adminUser()];
}

it('refunds in full when the admin decides so', function () {
    $x = escalatedIssue();

    $issue = issues()->adminResolve($x['admin'], $x['issue'], IssueResolution::FULL_REFUND, null, 'Tracking proves it never arrived.');

    expect($issue->status)->toBe(IssueStatus::RESOLVED)
        ->and($issue->resolution)->toBe(IssueResolution::FULL_REFUND)
        ->and($issue->refunded_cents)->toBe(6900)
        ->and($issue->resolved_by)->toBe($x['admin']->id)
        ->and($issue->messages->last()->body)->toContain('Decision: Full refund of $69.00.')->toContain('Tracking proves it never arrived.')
        ->and(fakeGateway()->lastCall('refund')['meta']['refunded_by'])->toBe($x['admin']->id)
        ->and($x['sellerOrder']->fresh()->status)->toBe(SellerOrderStatus::REFUNDED)
        ->and($x['order']->fresh()->status)->toBe(OrderStatus::REFUNDED);
});

it('refunds part of it when the admin decides so, and keeps the rules for the amount', function () {
    $x = escalatedIssue();

    foreach ([null, 0, 6900, 9999] as $bad) {
        expect(fn () => issues()->adminResolve($x['admin'], $x['issue'], IssueResolution::PARTIAL_REFUND, $bad))->toThrow(InvalidOrderStateException::class);
    }
    expect($x['issue']->fresh()->status)->toBe(IssueStatus::ESCALATED)->and(fakeGateway()->callCount('refund'))->toBe(0);

    $issue = issues()->adminResolve($x['admin'], $x['issue'], IssueResolution::PARTIAL_REFUND, 2500);

    $so = $x['sellerOrder']->fresh();
    expect($issue->resolution)->toBe(IssueResolution::PARTIAL_REFUND)
        ->and($issue->refunded_cents)->toBe(2500)
        ->and(fakeGateway()->lastCall('refund')['amountCents'])->toBe(2500)
        ->and($so->payout_cents)->toBe(3800)
        ->and($so->status)->toBe(SellerOrderStatus::SHIPPED);
});

it('closes without a refund when the admin takes no action, and the payout goes on', function () {
    $x = escalatedIssue();

    $issue = issues()->adminResolve($x['admin'], $x['issue'], IssueResolution::NO_ACTION, null, 'Tracking shows it was delivered.');

    expect($issue->resolution)->toBe(IssueResolution::NO_ACTION)
        ->and($issue->refunded_cents)->toBe(0)
        ->and(fakeGateway()->callCount('refund'))->toBe(0)
        ->and($issue->messages->last()->body)->toContain('Decision: no action.');

    $this->travel(12)->days(); // the 14 days after shipping are over now
    $this->artisan('marketplace:auto-complete')->assertSuccessful();
    expect($x['sellerOrder']->fresh()->isTransferred())->toBeTrue()
        ->and(fakeGateway()->lastCall('createTransfer')['amountCents'])->toBe(6300);
});

it('refuses decisions from anybody who is not an admin: 403', function () {
    $x = escalatedIssue();

    foreach ([$x['buyer'], $x['seller'], User::factory()->create(), onboardedSeller()] as $nobody) {
        expect(fn () => issues()->adminResolve($nobody, $x['issue'], IssueResolution::FULL_REFUND))->toThrow(AuthorizationException::class)
            ->and(fn () => issues()->adminResolve($nobody, $x['issue'], IssueResolution::NO_ACTION))->toThrow(AuthorizationException::class);
    }

    expect($x['issue']->fresh()->status)->toBe(IssueStatus::ESCALATED);
});

it('lets an admin only decide full, partial or no action, and only once', function () {
    $x = escalatedIssue();

    expect(fn () => issues()->adminResolve($x['admin'], $x['issue'], IssueResolution::WITHDRAWN))->toThrow(InvalidArgumentException::class)
        ->and(fn () => issues()->adminResolve($x['admin'], $x['issue'], IssueResolution::RETURN_REFUND))->toThrow(InvalidArgumentException::class)
        ->and(fn () => issues()->adminResolve($x['admin'], $x['issue'], IssueResolution::CHARGEBACK_LOST))->toThrow(InvalidArgumentException::class);

    issues()->adminResolve($x['admin'], $x['issue'], IssueResolution::NO_ACTION);
    expect(fn () => issues()->adminResolve($x['admin'], $x['issue']->fresh(), IssueResolution::FULL_REFUND))->toThrow(InvalidOrderStateException::class, 'resolved already');
});

it('can also settle an issue that is not escalated yet, as the admin', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    expect(issues()->adminResolve(adminUser(), $issue, IssueResolution::PARTIAL_REFUND, 1000)->status)->toBe(IssueStatus::RESOLVED);
});

it('leaves the issue escalated when Stripe refuses the admins refund', function () {
    $x = escalatedIssue();
    fakeGateway()->failOn('refund');

    expect(fn () => issues()->adminResolve($x['admin'], $x['issue'], IssueResolution::FULL_REFUND))->toThrow(PaymentGatewayException::class);

    expect($x['issue']->fresh()->status)->toBe(IssueStatus::ESCALATED)->and($x['sellerOrder']->fresh()->refunded_cents)->toBe(0);
});

it('refuses "no action" while a bank dispute is still open on the issue', function () {
    $x = reportableOrder();
    $issue = App\Models\OrderIssue::factory()->forSellerOrder($x['sellerOrder'])->escalated()->dispute('dp_open')->create();

    expect(fn () => issues()->adminResolve(adminUser(), $issue, IssueResolution::NO_ACTION))->toThrow(InvalidOrderStateException::class, 'bank dispute is still open')
        // refunding is possible: the dispute then closes as "charge refunded"
        ->and(issues()->adminResolve(adminUser(), $issue, IssueResolution::FULL_REFUND)->status)->toBe(IssueStatus::RESOLVED);
});

it('does not let the buyer withdraw while a bank dispute is open', function () {
    $x = reportableOrder();
    $issue = App\Models\OrderIssue::factory()->forSellerOrder($x['sellerOrder'])->escalated()->dispute('dp_open')->create();

    expect(fn () => issues()->withdraw($x['buyer'], $issue))->toThrow(InvalidOrderStateException::class, 'bank dispute');
});

// --- who hears about what ---------------------------------------------------------------------------------------------

it('tells admins and the other side about an escalation, not the one who escalated', function () {
    Notification::fake();
    $admin = adminUser();
    $x = reportableOrder();
    $issue = reportProblem($x);
    $this->travel(3)->days();

    issues()->escalate($x['buyer'], $issue);

    Notification::assertSentTo($admin, IssueEscalated::class, fn ($n) => $n->by?->is($x['buyer']));
    Notification::assertSentTo($x['seller'], IssueEscalated::class, 1);
    Notification::assertNotSentTo($x['buyer'], IssueEscalated::class);
});

it('tells the buyer and the seller about the decision, not the admin who made it', function () {
    Notification::fake();
    $x = escalatedIssue();

    issues()->adminResolve($x['admin'], $x['issue'], IssueResolution::PARTIAL_REFUND, 1000);

    Notification::assertSentTo($x['buyer'], IssueResolved::class, 1);
    Notification::assertSentTo($x['seller'], IssueResolved::class, 1);
    Notification::assertNotSentTo($x['admin'], IssueResolved::class);
});

it('tells the right people about messages: never the author, admins only once it is escalated', function () {
    $admin = adminUser();
    $x = reportableOrder();
    $issue = reportProblem($x);

    Notification::fake();
    issues()->reply($x['buyer'], $issue, 'Any news?');
    Notification::assertSentTo($x['seller'], IssueMessagePosted::class, 1);
    Notification::assertNotSentTo($x['buyer'], IssueMessagePosted::class);
    Notification::assertNotSentTo($admin, IssueMessagePosted::class);

    issues()->reply($x['seller'], $issue, 'Looking into it.');
    Notification::assertSentTo($x['buyer'], IssueMessagePosted::class, 1);

    issues()->internalNote($admin, $issue, 'private');
    Notification::assertSentTimes(IssueMessagePosted::class, 2); // the note notified nobody

    $this->travel(3)->days();
    issues()->escalate($x['seller'], $issue->fresh());
    issues()->reply($x['buyer'], $issue->fresh(), 'Please decide.');
    Notification::assertSentTo($admin, IssueMessagePosted::class, 1);
    Notification::assertSentTo($x['seller'], IssueMessagePosted::class, 2);

    issues()->reply($admin, $issue->fresh(), 'We will look at it today.');
    Notification::assertSentTo($x['buyer'], IssueMessagePosted::class, 2);
    Notification::assertSentTo($x['seller'], IssueMessagePosted::class, 3);
    Notification::assertNotSentTo($admin, IssueMessagePosted::class, fn ($n) => $n->message->author_id === $admin->id);
});

it('builds mails and database payloads for every problem notification', function () {
    $x = escalatedIssue();
    $issue = $x['issue'];
    $admin = $x['admin'];
    $issue->forceFill(['offer_type' => IssueResolution::PARTIAL_REFUND, 'offer_cents' => 1500, 'refunded_cents' => 1500, 'resolution' => IssueResolution::PARTIAL_REFUND])->save();
    $message = $issue->messages->last();

    $cases = [
        'issue_opened' => [new App\Notifications\IssueOpened($issue), $x['seller']],
        'issue_message' => [new IssueMessagePosted($issue, $message), $x['buyer']],
        'issue_offer' => [new App\Notifications\IssueOfferMade($issue), $x['buyer']],
        'issue_offer_answered' => [new App\Notifications\IssueOfferAnswered($issue, true), $x['seller']],
        'issue_escalated' => [new IssueEscalated($issue, $x['buyer']), $admin],
        'issue_resolved' => [new IssueResolved($issue), $x['buyer']],
        'dispute_opened' => [new App\Notifications\DisputeOpened($x['order'], [$issue], ['id' => 'dp_1', 'amount' => 6900, 'reason' => 'fraudulent', 'evidence_details' => ['due_by' => now()->addDays(7)->timestamp]]), $admin],
    ];

    foreach ($cases as $kind => [$notification, $user]) {
        $data = $notification->toArray($user);
        $html = (string) $notification->toMail($user)->render();

        expect($data)->toHaveKeys(['title', 'body', 'url', 'link', 'icon', 'user', 'kind', 'order_number'])
            ->and($data['kind'])->toBe($kind)
            ->and($data['title'])->not->toBe('')
            ->and($data['body'])->not->toBe('')
            ->and($data['url'])->toStartWith('http')->and($data['link'])->toBe($data['url'])
            ->and($html)->toContain($x['order']->number)->toContain('class="button')->toContain('<table');
    }

    // admins land in the Filament queue, everybody else on the shared issue page
    expect($cases['issue_escalated'][0]->toArray($admin)['url'])->toBe(App\Filament\Resources\OrderIssueResource::getUrl('view', ['record' => $issue]))
        ->and($cases['issue_resolved'][0]->toArray($x['buyer'])['url'])->toBe(App\Support\RecipientLink::to($issue->url(), $x['buyer']));
});
