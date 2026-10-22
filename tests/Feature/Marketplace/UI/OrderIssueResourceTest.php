<?php

use App\Enums\IssueReason;
use App\Enums\IssueResolution;
use App\Enums\IssueStatus;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Filament\Resources\OrderIssueResource;
use App\Filament\Resources\OrderIssueResource\Pages\ListOrderIssues;
use App\Filament\Resources\OrderIssueResource\Pages\ViewOrderIssue;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\Resources\OrderResource\RelationManagers\IssuesRelationManager;
use App\Filament\Resources\OrderResource\RelationManagers\SellerOrdersRelationManager;
use App\Models\OrderIssue;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| R2.2 admin: the problem report queue (navigation badge, tabs, filters), the case page (conversation, internal notes,
| decisions through IssueService with the admin as the actor), and the link from the order.
*/

beforeEach(function () {
    Storage::fake('private');
    config([
        'marketplace.fee_bps' => 1000, 'marketplace.fee_fixed_cents' => 0,
        'marketplace.physical_payout_trigger' => 'shipped_delay', 'marketplace.physical_payout_delay_days' => 14,
        'marketplace.issue_escalation_days' => 3,
    ]);
    $this->admin = adminUser();
});

/** @return array{buyer: User, seller: User, order: App\Models\Order, sellerOrder: App\Models\SellerOrder, issue: OrderIssue} */
function escalatedCase(): array
{
    $x = reportableOrder();
    $issue = reportProblem($x, IssueReason::NOT_AS_DESCRIBED, 'The surround is cracked and it was sold as perfect.');
    test()->travel(3)->days();
    issues()->escalate($x['buyer'], $issue, 'No answer from the seller.');

    return $x + ['issue' => $issue->fresh()];
}

// --- access ---------------------------------------------------------------------------------------------------

it('opens the queue and a case for admins only', function () {
    $x = escalatedCase();
    $case = OrderIssueResource::getUrl('view', ['record' => $x['issue']]);

    $this->actingAs($this->admin)->get('/admin/order-issues')->assertOk()->assertSee('Problem reports');
    $this->actingAs($this->admin)->get($case)->assertOk()->assertSee($x['order']->number)->assertSee('The surround is cracked');

    foreach ([$x['buyer'], $x['seller'], User::factory()->create()] as $other) {
        $this->actingAs($other)->get('/admin/order-issues')->assertForbidden();
        $this->actingAs($other)->get($case)->assertForbidden();
    }
});

it('sends guests to the login', function () {
    $this->get('/admin/order-issues')->assertRedirect();
});

it('is in the Marketplace group with the number of cases waiting for a decision as its badge', function () {
    $before = OrderIssue::where('status', IssueStatus::ESCALATED->value)->count();

    expect(OrderIssueResource::getNavigationGroup())->toBe('Marketplace');

    $x = escalatedCase();
    $y = reportableOrder();
    reportProblem($y); // open, not escalated: does not count

    $this->actingAs($this->admin);
    expect(OrderIssueResource::getNavigationBadge())->toBe((string) ($before + 1))
        ->and(OrderIssueResource::getNavigationBadgeColor())->toBe('danger');

    issues()->adminResolve($this->admin, $x['issue'], IssueResolution::NO_ACTION);
    expect(OrderIssueResource::getNavigationBadge())->toBe($before > 0 ? (string) $before : null);

    $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee('Problem reports');
});

// --- the queue --------------------------------------------------------------------------------------------------

it('lists the cases in tabs, the escalated ones first', function () {
    $x = escalatedCase();
    $open = reportableOrder();
    $openIssue = reportProblem($open);
    $done = reportableOrder();
    $doneIssue = reportProblem($done);
    issues()->withdraw($done['buyer'], $doneIssue);

    Livewire::actingAs($this->admin)->test(ListOrderIssues::class)
        ->assertSet('activeTab', 'escalated')
        ->assertCanSeeTableRecords([$x['issue']])
        ->assertCanNotSeeTableRecords([$openIssue, $doneIssue])
        ->set('activeTab', 'negotiating')
        ->assertCanSeeTableRecords([$openIssue])
        ->assertCanNotSeeTableRecords([$x['issue'], $doneIssue])
        ->set('activeTab', 'resolved')
        ->assertCanSeeTableRecords([$doneIssue])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$x['issue'], $openIssue, $doneIssue]);
});

it('filters by status, reason, resolution, dispute and unrecovered lost disputes', function () {
    $a = escalatedCase();
    $b = reportableOrder();
    $damaged = reportProblem($b, IssueReason::DAMAGED, 'Arrived with a dent in the cone.');
    $c = reportableOrder();
    $lost = OrderIssue::factory()->forSellerOrder($c['sellerOrder'])->dispute('dp_x')->resolved(IssueResolution::CHARGEBACK_LOST)->create();

    $list = Livewire::actingAs($this->admin)->test(ListOrderIssues::class)->set('activeTab', 'all');

    $list->filterTable('reason', [IssueReason::DAMAGED->value])->assertCanSeeTableRecords([$damaged])->assertCanNotSeeTableRecords([$a['issue'], $lost])
        ->resetTableFilters()
        ->filterTable('status', [IssueStatus::ESCALATED->value])->assertCanSeeTableRecords([$a['issue']])->assertCanNotSeeTableRecords([$damaged, $lost])
        ->resetTableFilters()
        ->filterTable('dispute', true)->assertCanSeeTableRecords([$lost])->assertCanNotSeeTableRecords([$damaged, $a['issue']])
        ->resetTableFilters()
        ->filterTable('recover', true)->assertCanSeeTableRecords([$lost])->assertCanNotSeeTableRecords([$damaged, $a['issue']])
        ->resetTableFilters()
        ->filterTable('resolution', [IssueResolution::CHARGEBACK_LOST->value])->assertCanSeeTableRecords([$lost])->assertCanNotSeeTableRecords([$damaged]);
});

it('searches the queue by order number and buyer', function () {
    $a = escalatedCase();
    $b = escalatedCase();

    Livewire::actingAs($this->admin)->test(ListOrderIssues::class)
        ->searchTable($a['order']->number)->assertCanSeeTableRecords([$a['issue']])->assertCanNotSeeTableRecords([$b['issue']])
        ->searchTable($b['buyer']->email)->assertCanSeeTableRecords([$b['issue']])->assertCanNotSeeTableRecords([$a['issue']]);
});

// --- the case page ------------------------------------------------------------------------------------------------

it('shows the case: facts, order, payout, and the whole conversation including internal notes', function () {
    $x = escalatedCase();
    issues()->reply($x['seller'], $x['issue'], 'The cone was fine when it left my shop.');
    issues()->internalNote($this->admin, $x['issue'], 'Seller has two earlier complaints.');

    $this->actingAs($this->admin)->get(OrderIssueResource::getUrl('view', ['record' => $x['issue']]))
        ->assertOk()
        ->assertSee('Escalated')
        ->assertSee('Not as described')
        ->assertSee($x['order']->number)
        ->assertSee('$69.00')                       // paid for this part
        ->assertSee('on hold')                      // the payout
        ->assertSee('The surround is cracked and it was sold as perfect.')
        ->assertSee('The cone was fine when it left my shop.')
        ->assertSee('Seller has two earlier complaints.')->assertSee('Internal note')
        ->assertSee('No answer from the seller.')
        ->assertSee(OrderResource::getUrl('view', ['record' => $x['order']]), false);
});

it('replies and writes internal notes from the case page', function () {
    $x = escalatedCase();
    $page = Livewire::actingAs($this->admin)->test(ViewOrderIssue::class, ['record' => $x['issue']->getRouteKey()]);

    $page->callAction('reply', ['body' => 'We are looking at the pictures now.'])->assertHasNoActionErrors()->assertNotified('Message sent');
    $page->callAction('note', ['body' => 'Waiting for the carrier.'])->assertHasNoActionErrors()->assertNotified('Note saved');

    $messages = $x['issue']->fresh()->messages;
    expect($messages->where('body', 'We are looking at the pictures now.')->first()->internal)->toBeFalse()
        ->and($messages->where('body', 'Waiting for the carrier.')->first()->internal)->toBeTrue()
        ->and($x['buyer']->notifications()->where('data->kind', 'issue_message')->count())->toBe(1)
        ->and($x['seller']->notifications()->where('data->kind', 'issue_message')->count())->toBe(1);

    $page->callAction('reply', ['body' => ''])->assertHasActionErrors(['body' => 'required']);
});

it('refunds in full from the case page, as the admin', function () {
    $x = escalatedCase();

    Livewire::actingAs($this->admin)->test(ViewOrderIssue::class, ['record' => $x['issue']->getRouteKey()])
        ->assertActionVisible('fullRefund')->assertActionVisible('partialRefund')->assertActionVisible('noAction')
        ->assertActionHidden('recover')
        ->callAction('fullRefund', ['note' => 'The pictures are clear.'])
        ->assertNotified('Refunded in full');

    $issue = $x['issue']->fresh();
    expect($issue->status)->toBe(IssueStatus::RESOLVED)
        ->and($issue->resolution)->toBe(IssueResolution::FULL_REFUND)
        ->and($issue->refunded_cents)->toBe(6900)
        ->and($issue->resolved_by)->toBe($this->admin->id)
        ->and(fakeGateway()->lastCall('refund')['meta']['refunded_by'])->toBe($this->admin->id)
        ->and($x['sellerOrder']->fresh()->status)->toBe(SellerOrderStatus::REFUNDED)
        ->and($x['order']->fresh()->status)->toBe(OrderStatus::REFUNDED)
        ->and($issue->messages->last()->body)->toContain('The pictures are clear.');
});

it('refunds part of it from the case page with an amount', function () {
    $x = escalatedCase();
    $page = Livewire::actingAs($this->admin)->test(ViewOrderIssue::class, ['record' => $x['issue']->getRouteKey()]);

    $page->callAction('partialRefund', ['amount' => '', 'note' => ''])->assertHasActionErrors(['amount']);
    $page->callAction('partialRefund', ['amount' => '69.00'])->assertHasActionErrors(['amount']); // everything is a full refund
    expect($x['issue']->fresh()->status)->toBe(IssueStatus::ESCALATED);

    $page->callAction('partialRefund', ['amount' => '25.00', 'note' => 'Half the price for the crack.'])->assertHasNoActionErrors()->assertNotified('Partly refunded');

    $issue = $x['issue']->fresh();
    expect($issue->resolution)->toBe(IssueResolution::PARTIAL_REFUND)
        ->and($issue->refunded_cents)->toBe(2500)
        ->and(fakeGateway()->lastCall('refund')['amountCents'])->toBe(2500)
        ->and($x['sellerOrder']->fresh()->payout_cents)->toBe(3800);
});

it('closes a case without a refund from the case page', function () {
    $x = escalatedCase();

    Livewire::actingAs($this->admin)->test(ViewOrderIssue::class, ['record' => $x['issue']->getRouteKey()])
        ->callAction('noAction', ['note' => 'Seller proved it was perfect when shipped.'])
        ->assertNotified('Case closed');

    expect($x['issue']->fresh()->resolution)->toBe(IssueResolution::NO_ACTION)
        ->and(fakeGateway()->callCount('refund'))->toBe(0);
});

it('hides the decisions once the case is resolved', function () {
    $x = escalatedCase();
    issues()->adminResolve($this->admin, $x['issue'], IssueResolution::NO_ACTION);

    Livewire::actingAs($this->admin)->test(ViewOrderIssue::class, ['record' => $x['issue']->getRouteKey()])
        ->assertActionHidden('fullRefund')->assertActionHidden('partialRefund')->assertActionHidden('noAction')->assertActionHidden('reply')
        ->assertActionVisible('note');
});

it('shows Stripes refusal as a notification and leaves the case escalated', function () {
    $x = escalatedCase();
    fakeGateway()->failOn('refund');

    Livewire::actingAs($this->admin)->test(ViewOrderIssue::class, ['record' => $x['issue']->getRouteKey()])
        ->callAction('fullRefund')
        ->assertNotified('Stripe refused');

    expect($x['issue']->fresh()->status)->toBe(IssueStatus::ESCALATED);
});

it('recovers a lost dispute from the seller from the case page', function () {
    $x = reportableOrder();
    $lost = OrderIssue::factory()->forSellerOrder($x['sellerOrder'])->dispute('dp_lost')->resolved(IssueResolution::CHARGEBACK_LOST)->create();

    Livewire::actingAs($this->admin)->test(ViewOrderIssue::class, ['record' => $lost->getRouteKey()])
        ->assertActionVisible('recover')->assertActionHidden('fullRefund')
        ->assertSee('Bank dispute')->assertSee('dp_lost')->assertSee('not recovered')
        ->callAction('recover')
        ->assertNotified('Recovered from the seller')
        ->assertActionHidden('recover');

    $lost->refresh();
    expect($lost->recovered_at)->not->toBeNull()
        ->and($lost->recovered_cents)->toBe(6300)
        ->and($x['sellerOrder']->fresh()->payout_cents)->toBe(0); // the pending payout is cancelled
});

it('lets nobody but an admin act on the case page', function () {
    $x = escalatedCase();

    foreach ([$x['buyer'], $x['seller']] as $user) {
        Livewire::actingAs($user)->test(ViewOrderIssue::class, ['record' => $x['issue']->getRouteKey()])->assertForbidden();
    }

    expect($x['issue']->fresh()->status)->toBe(IssueStatus::ESCALATED);
});

// --- orders -----------------------------------------------------------------------------------------------------

it('links an order to its problem reports', function () {
    $x = escalatedCase();
    $quiet = paidListingOrder(User::factory()->create())['order'];

    Livewire::actingAs($this->admin)->test(IssuesRelationManager::class, ['ownerRecord' => $x['order'], 'pageClass' => ViewOrder::class])
        ->assertCanSeeTableRecords([$x['issue']])
        ->assertSee('Escalated')->assertSee('Not as described');

    Livewire::actingAs($this->admin)->test(IssuesRelationManager::class, ['ownerRecord' => $quiet, 'pageClass' => ViewOrder::class])
        ->assertSee('No problem reports');

    $this->actingAs($this->admin)->get(OrderResource::getUrl('view', ['record' => $x['order']]))->assertOk()->assertSee('Problem reports');

    Livewire::actingAs($this->admin)->test(OrderResource\Pages\ListOrders::class)
        ->filterTable('has_issue', true)->assertCanSeeTableRecords([$x['order']])->assertCanNotSeeTableRecords([$quiet]);
});

it('shows the problem on the seller orders of an order', function () {
    $x = escalatedCase();

    Livewire::actingAs($this->admin)->test(SellerOrdersRelationManager::class, ['ownerRecord' => $x['order'], 'pageClass' => ViewOrder::class])
        ->assertCanSeeTableRecords([$x['sellerOrder']])
        ->assertSee('Escalated');
});

it('refunds part of a seller order from the order page and keeps the payout reversal retryable', function () {
    config(['marketplace.physical_payout_trigger' => 'shipped']);
    $x = reportableOrder(); // paid out at shipping: 6300 transferred
    $manager = fn () => Livewire::actingAs($this->admin)->test(SellerOrdersRelationManager::class, ['ownerRecord' => $x['order'], 'pageClass' => ViewOrder::class]);

    fakeGateway()->failOn('reverseTransfer');
    $manager()->callTableAction('refundPartially', $x['sellerOrder'], data: ['amount' => '10.00', 'reason' => 'goodwill'])->assertNotified('Refunded $10.00');

    $so = $x['sellerOrder']->fresh();
    expect($so->refunded_cents)->toBe(1000)->and($so->reversal_pending_cents)->toBe(1000)->and($so->status)->toBe(SellerOrderStatus::SHIPPED);

    $manager()->assertSee('payout reversal pending')->callTableAction('retryReversal', $so)->assertNotified('Transfer reversed');

    expect($so->fresh()->reversal_pending_cents)->toBe(0)->and($so->fresh()->reversed_cents)->toBe(1000);
});
