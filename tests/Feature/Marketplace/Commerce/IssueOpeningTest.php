<?php

use App\Enums\IssueReason;
use App\Enums\IssueStatus;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Models\OrderIssue;
use App\Models\User;
use App\Services\Marketplace\RefundService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| R2.2: opening a problem report: who may, when (the window), what is required, and that SDLabs' own orders skip the
| negotiation.
*/

beforeEach(function () {
    $this->issuesBefore = OrderIssue::count(); // the demo data has problem reports of its own
    Storage::fake('private');
    config([
        'marketplace.fee_bps' => 1000, 'marketplace.fee_fixed_cents' => 0,
        'marketplace.physical_payout_trigger' => 'shipped_delay', 'marketplace.physical_payout_delay_days' => 14,
        'marketplace.issue_window_days' => 14, 'marketplace.issue_escalation_days' => 3,
    ]);
});

it('opens an issue for a shipped parcel: status, parties, first message, notified seller', function () {
    $x = reportableOrder();

    $issue = reportProblem($x, IssueReason::DAMAGED, 'The box was crushed and the cone is dented.');

    expect($issue->status)->toBe(IssueStatus::OPEN)
        ->and($issue->reason)->toBe(IssueReason::DAMAGED)
        ->and($issue->buyer_id)->toBe($x['buyer']->id)
        ->and($issue->seller_id)->toBe($x['seller']->id)
        ->and($issue->order_id)->toBe($x['order']->id)
        ->and($issue->seller_order_id)->toBe($x['sellerOrder']->id)
        ->and($issue->resolution)->toBeNull()
        ->and($issue->refunded_cents)->toBe(0)
        ->and($issue->messages)->toHaveCount(1)
        ->and($issue->messages->first()->body)->toBe('The box was crushed and the cone is dented.')
        ->and($issue->messages->first()->author_id)->toBe($x['buyer']->id)
        ->and($issue->messages->first()->event)->toBe('opened')
        ->and($issue->messages->first()->internal)->toBeFalse()
        ->and($issue->last_message_at)->not->toBeNull()
        ->and($x['seller']->notifications()->where('data->kind', 'issue_opened')->count())->toBe(1)
        ->and($x['buyer']->notifications()->count())->toBe(2); // receipt + shipped, nothing about its own report
});

it('lets the buyer report an unshipped parcel at any time', function () {
    $x = reportableOrder('paid');

    expect(issues()->canReport($x['sellerOrder']))->toBeTrue()
        ->and(issues()->windowEndsAt($x['sellerOrder']))->toBeNull();

    $this->travel(60)->days();
    $issue = reportProblem($x, IssueReason::NOT_SHIPPED, 'Two months and nothing has shipped yet.');

    expect($issue->status)->toBe(IssueStatus::OPEN)->and($issue->reason)->toBe(IssueReason::NOT_SHIPPED);
});

it('closes the window 14 days after shipping', function () {
    $x = reportableOrder();
    $so = $x['sellerOrder'];
    $end = $so->shipped_at->copy()->addDays(14);

    expect(issues()->windowEndsAt($so)->equalTo($end))->toBeTrue();

    $this->travelTo($end); // the last moment
    expect(issues()->canReport($so->fresh()))->toBeTrue();

    $this->travelTo($end->copy()->addSecond());
    expect(issues()->canReport($so->fresh()))->toBeFalse()
        ->and(issues()->reportBlocker($so->fresh()))->toContain('ended on '.$end->format('F j, Y'));
    expect(fn () => reportProblem($x))->toThrow(InvalidOrderStateException::class, 'ended on');
    expect((OrderIssue::count() - $this->issuesBefore))->toBe(0);
});

it('counts digital items from the payment: 14 days', function () {
    $buyer = User::factory()->create();
    $order = pendingOrder($buyer, [paidDesign(onboardedSeller(), 2500)]);
    payOrder($order, 'pi_dig', 'ch_dig');
    $so = $order->sellerOrders()->first();
    $paid = $order->fresh()->paid_at;

    expect(issues()->windowEndsAt($so)->equalTo($paid->copy()->addDays(14)))->toBeTrue()
        ->and(IssueReason::forSellerOrder($so))->toBe([IssueReason::DOWNLOAD_PROBLEM, IssueReason::NOT_AS_DESCRIBED, IssueReason::OTHER]);

    $this->travelTo($paid->copy()->addDays(14));
    $issue = issues()->open($buyer, $so->fresh(), IssueReason::DOWNLOAD_PROBLEM, 'The ZIP does not open for me.');
    expect($issue->status)->toBe(IssueStatus::OPEN);
    issues()->withdraw($buyer, $issue);

    $this->travelTo($paid->copy()->addDays(14)->addSecond());
    expect(fn () => issues()->open($buyer, $so->fresh(), IssueReason::DOWNLOAD_PROBLEM, 'Still does not open.'))
        ->toThrow(InvalidOrderStateException::class);
});

it('only offers reasons that fit the state of the seller order', function () {
    $unshipped = reportableOrder('paid');
    $shipped = reportableOrder();

    expect(IssueReason::forSellerOrder($unshipped['sellerOrder']))->toBe([IssueReason::NOT_SHIPPED, IssueReason::NOT_AS_DESCRIBED, IssueReason::OTHER])
        ->and(IssueReason::forSellerOrder($shipped['sellerOrder']))->toBe([IssueReason::NOT_RECEIVED, IssueReason::NOT_AS_DESCRIBED, IssueReason::DAMAGED, IssueReason::WRONG_ITEM, IssueReason::OTHER])
        ->and(IssueReason::optionsFor($shipped['sellerOrder']))->toHaveKeys(['not_received', 'damaged'])
        ->and(IssueReason::NOT_AS_DESCRIBED->label())->toBe('Not as described')
        ->and(IssueReason::cases())->toHaveCount(7);

    expect(fn () => reportProblem($unshipped, IssueReason::NOT_RECEIVED))->toThrow(ValidationException::class)
        ->and(fn () => reportProblem($unshipped, IssueReason::DOWNLOAD_PROBLEM))->toThrow(ValidationException::class)
        ->and(fn () => reportProblem($shipped, IssueReason::NOT_SHIPPED))->toThrow(ValidationException::class)
        ->and((OrderIssue::count() - $this->issuesBefore))->toBe(0);
});

it('needs a description of at least a few words', function () {
    $x = reportableOrder();

    expect(fn () => reportProblem($x, IssueReason::OTHER, 'bad'))->toThrow(ValidationException::class)
        ->and(fn () => reportProblem($x, IssueReason::OTHER, '   '))->toThrow(ValidationException::class)
        ->and(fn () => reportProblem($x, IssueReason::OTHER, str_repeat('x', 5001)))->toThrow(ValidationException::class)
        ->and((OrderIssue::count() - $this->issuesBefore))->toBe(0);
});

it('allows one unresolved issue per seller order, and a new one after it is resolved', function () {
    $x = reportableOrder();
    $first = reportProblem($x);

    expect(fn () => reportProblem($x))->toThrow(InvalidOrderStateException::class, 'already an open problem')
        ->and((OrderIssue::count() - $this->issuesBefore))->toBe(1);

    // the database enforces it as well
    expect(fn () => OrderIssue::factory()->forSellerOrder($x['sellerOrder'])->create())->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);

    issues()->withdraw($x['buyer'], $first);
    $second = reportProblem($x, IssueReason::DAMAGED, 'Actually it is damaged as well, I noticed later.');

    expect((OrderIssue::count() - $this->issuesBefore))->toBe(2)->and($second->status)->toBe(IssueStatus::OPEN)
        ->and($x['sellerOrder']->fresh()->issues->pluck('id')->all())->toBe([$second->id, $first->id]); // newest first
});

it('keeps another seller order of the same order independent', function () {
    $buyer = User::factory()->create();
    $one = onboardedSeller();
    $two = onboardedSeller();
    $order = pendingOrder($buyer, [sellableListing($one), sellableListing($two)], addressFor($buyer));
    payOrder($order, 'pi_two', 'ch_two');
    [$soOne, $soTwo] = $order->sellerOrders()->orderBy('id')->get()->all();

    $a = issues()->open($buyer, $soOne, IssueReason::NOT_SHIPPED, 'Seller one has not shipped anything.');
    $b = issues()->open($buyer, $soTwo, IssueReason::NOT_SHIPPED, 'Seller two has not shipped either.');

    expect($a->seller_id)->toBe($soOne->seller_id)->and($b->seller_id)->toBe($soTwo->seller_id)
        ->and($order->issues)->toHaveCount(2)
        ->and($soOne->fresh()->activeIssue->is($a))->toBeTrue()
        ->and($soOne->fresh()->hasUnresolvedIssue())->toBeTrue();
});

it('refuses refunded, unpaid and cancelled seller orders', function () {
    $x = reportableOrder();
    app(RefundService::class)->refundOrder(adminUser(), $x['order']);
    expect(fn () => reportProblem($x))->toThrow(InvalidOrderStateException::class, 'refunded already');

    $buyer = User::factory()->create();
    $pending = pendingOrder($buyer, [sellableListing()], addressFor($buyer));
    expect(fn () => issues()->open($buyer, $pending->sellerOrders()->first(), IssueReason::OTHER, 'It is not paid at all yet.'))
        ->toThrow(InvalidOrderStateException::class, 'not been paid');
});

it('refuses everybody but the buyer of the order: 403', function () {
    $x = reportableOrder();

    foreach ([$x['seller'], adminUser(), User::factory()->create(), onboardedSeller()] as $intruder) {
        expect(fn () => issues()->open($intruder, $x['sellerOrder'], IssueReason::OTHER, 'Not my order at all, really.'))
            ->toThrow(AuthorizationException::class);
    }

    expect((OrderIssue::count() - $this->issuesBefore))->toBe(0);
});

it('sends SDLabs store orders straight to the admins as an escalated issue', function () {
    $buyer = User::factory()->create();
    $store = storeListing(['price_cents' => 12900, 'shipping_domestic_cents' => 900, 'quantity' => 5]);
    $order = pendingOrder($buyer, [$store], addressFor($buyer));
    payOrder($order, 'pi_store', 'ch_store');
    $so = $order->sellerOrders()->sole();
    $admin = $store->seller; // the platform seller
    $otherAdmin = adminUser();

    $issue = issues()->open($buyer, $so, IssueReason::NOT_SHIPPED, 'Ordered a week ago, no tracking yet.');

    expect($issue->status)->toBe(IssueStatus::ESCALATED)
        ->and($issue->escalated_at)->not->toBeNull()
        ->and($issue->escalated_by)->toBeNull()
        ->and($issue->messages)->toHaveCount(2)
        ->and($issue->messages->last()->isSystem())->toBeTrue()
        ->and($issue->messages->last()->body)->toContain('is the seller of this order')
        // the admins hear about it; the buyer does not hear about their own report
        ->and($admin->notifications()->where('data->kind', 'issue_escalated')->count())->toBe(1)
        ->and($otherAdmin->notifications()->where('data->kind', 'issue_escalated')->count())->toBe(1)
        ->and($buyer->notifications()->where('data->kind', 'like', 'issue_%')->count())->toBe(0);

    // nobody to negotiate with: the seller side cannot make an offer, only the admins decide
    expect(issues()->can($admin, $issue, 'makeOffer'))->toBeFalse()
        ->and(issues()->can($otherAdmin, $issue, 'decide'))->toBeTrue();
});

it('reports the payout hold state to the issue and back', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    expect($issue->isUnresolved())->toBeTrue()
        ->and($issue->escalatableAt()->equalTo($issue->created_at->copy()->addDays(3)))->toBeTrue()
        ->and($issue->escalationOpen())->toBeFalse()
        ->and($issue->refundableCents())->toBe(6900)
        ->and($issue->sellerOrder->refundableCents())->toBe(6900)
        ->and($issue->isDispute())->toBeFalse()
        ->and($issue->url())->toBe(url('/dashboard/issues/'.$issue->id));
});
