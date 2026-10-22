<?php

use App\Enums\IssueReason;
use App\Enums\IssueResolution;
use App\Enums\IssueStatus;
use App\Http\Livewire\OrderIssuePanel;
use App\Models\OrderIssue;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| R2.2 pages: "Report a problem" and the issue panel on the buyer's order page, the shared issue page, the sellers
| sales page and selling dashboard, and the issue list.
*/

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('private');
    $this->issuesBefore = OrderIssue::count(); // the demo data has problem reports of its own
    config([
        'marketplace.fee_bps' => 1000, 'marketplace.fee_fixed_cents' => 0,
        'marketplace.physical_payout_trigger' => 'shipped_delay', 'marketplace.physical_payout_delay_days' => 14,
        'marketplace.issue_window_days' => 14, 'marketplace.issue_escalation_days' => 3,
    ]);
});

function orderUrl(array $x): string
{
    return route('dashboard.orders.show', ['order' => $x['order']->number]);
}

function panel(User $user, OrderIssue $issue)
{
    return Livewire::actingAs($user)->test(OrderIssuePanel::class, ['issue' => $issue]);
}

// --- buyer order page: report a problem ---------------------------------------------------------------------------------

it('offers "Report a problem" on a shipped parcel while the window is open, with the end date', function () {
    $x = reportableOrder();
    $end = $x['sellerOrder']->shipped_at->copy()->addDays(14);

    $this->actingAs($x['buyer'])->get(orderUrl($x))
        ->assertOk()
        ->assertSee('Report a problem')
        ->assertSeeHtml('data-problem="report"')
        ->assertSee('until '.$end->format('M j, Y'))
        ->assertSee('Contact seller')
        ->assertSee(url('/chatify/'.$x['seller']->id), false);
});

it('offers it for an unshipped parcel at any time', function () {
    $x = reportableOrder('paid');

    $this->actingAs($x['buyer'])->get(orderUrl($x))
        ->assertOk()->assertSee('Report a problem')->assertSee('at any time until the seller ships');
});

it('says when the time to report a problem has ended and offers no button', function () {
    $x = reportableOrder();
    $this->travel(15)->days();

    $this->actingAs($x['buyer'])->get(orderUrl($x))
        ->assertOk()
        ->assertDontSeeHtml('data-problem="report"')
        ->assertSeeHtml('data-problem="closed"')
        ->assertSee('The time to report a problem ended on');
});

it('shows the seller and an admin the order without a report button, and the seller a Contact buyer link', function () {
    $x = reportableOrder();

    $this->actingAs($x['seller'])->get(orderUrl($x))
        ->assertOk()->assertDontSee('Report a problem')->assertSee('Contact buyer')->assertSee(url('/chatify/'.$x['buyer']->id), false);
    $this->actingAs(adminUser())->get(orderUrl($x))->assertOk()->assertDontSee('Report a problem');
});

it('reports a problem from the order page with the form', function () {
    $x = reportableOrder();

    voltPage($this, $x['buyer'], orderUrl($x), 'dashboard.orders.show', ['order' => $x['order']])
        ->callAction('reportProblem', [
            'reason' => IssueReason::DAMAGED->value,
            'description' => 'The box arrived crushed and the cone is dented.',
        ], ['sellerOrder' => $x['sellerOrder']->id])
        ->assertHasNoActionErrors()
        ->assertNotified('Problem reported');

    $issue = OrderIssue::where('seller_order_id', $x['sellerOrder']->id)->sole();
    expect($issue->reason)->toBe(IssueReason::DAMAGED)
        ->and($issue->status)->toBe(IssueStatus::OPEN)
        ->and($issue->buyer_id)->toBe($x['buyer']->id)
        ->and($issue->messages->first()->body)->toBe('The box arrived crushed and the cone is dented.');
});

it('validates the report form: reason and description are required, the reason must fit', function () {
    $x = reportableOrder();
    $page = voltPage($this, $x['buyer'], orderUrl($x), 'dashboard.orders.show', ['order' => $x['order']]);
    $args = ['sellerOrder' => $x['sellerOrder']->id];

    $page->callAction('reportProblem', ['reason' => null, 'description' => null], $args)->assertHasActionErrors(['reason' => 'required', 'description' => 'required']);
    $page->callAction('reportProblem', ['reason' => IssueReason::DAMAGED->value, 'description' => 'too short'], $args)->assertHasActionErrors(['description']);

    expect(OrderIssue::where('seller_order_id', $x['sellerOrder']->id)->count())->toBe(0);
});

it('takes photos in the report form', function () {
    $x = reportableOrder();

    voltPage($this, $x['buyer'], orderUrl($x), 'dashboard.orders.show', ['order' => $x['order']])
        ->callAction('reportProblem', [
            'reason' => IssueReason::DAMAGED->value,
            'description' => 'Pictures of the dent are attached here.',
            'photos' => [UploadedFile::fake()->image('dent.jpg', 40, 30), UploadedFile::fake()->image('box.png', 40, 30)],
        ], ['sellerOrder' => $x['sellerOrder']->id])
        ->assertHasNoActionErrors();

    $issue = OrderIssue::where('seller_order_id', $x['sellerOrder']->id)->sole();
    expect($issue->messages->first()->files())->toHaveCount(2);
    Storage::disk('private')->assertExists($issue->messages->first()->files()[0]['path']);
});

it('shows the open problem inside the order page instead of the report button', function () {
    $x = reportableOrder();
    $issue = reportProblem($x, IssueReason::DAMAGED, 'The box arrived crushed and the cone is dented.');

    $this->actingAs($x['buyer'])->get(orderUrl($x))
        ->assertOk()
        ->assertSeeHtml('data-problem="open"')
        ->assertDontSeeHtml('data-problem="report"')
        ->assertSee('Problem: Arrived damaged')
        ->assertSee('The box arrived crushed and the cone is dented.')
        ->assertSee('Open')
        // while a problem is open the buyer cannot confirm the parcel
        ->assertDontSee('Confirm received');
});

it('shows a resolved problem as a summary line with a link', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::PARTIAL_REFUND, 2000);
    issues()->acceptOffer($x['buyer'], $issue->fresh());

    $this->actingAs($x['buyer'])->get(orderUrl($x))
        ->assertOk()
        ->assertSeeHtml('data-problem="resolved"')
        ->assertSee('Partial refund')
        ->assertSee('$20.00 refunded')
        ->assertSee($issue->url(), false)
        ->assertSeeHtml('data-partial-refund');
});

// --- the panel: what each person can do ---------------------------------------------------------------------------------

it('lets the seller make an offer from the panel and shows it to the buyer', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    panel($x['seller'], $issue)
        ->assertSee('Make an offer')
        ->callAction('offer', ['type' => IssueResolution::PARTIAL_REFUND->value, 'amount' => '15.50', 'note' => 'Sorry!'])
        ->assertHasNoActionErrors()
        ->assertNotified('Offer sent');

    $issue->refresh();
    expect($issue->status)->toBe(IssueStatus::OFFER_PENDING)->and($issue->offer_cents)->toBe(1550);

    panel($x['buyer'], $issue)
        ->assertSee('The seller&#039;s offer', false)
        ->assertSee('A partial refund of $15.50')
        ->assertSee('Accept the offer')
        ->assertSee('Decline')
        ->assertDontSee('Make an offer');
});

it('refuses an offer that is too high with a notification, not an error page', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    panel($x['seller'], $issue)
        ->callAction('offer', ['type' => IssueResolution::PARTIAL_REFUND->value, 'amount' => '500.00'])
        ->assertNotified('That did not work');

    expect($issue->fresh()->status)->toBe(IssueStatus::OPEN);
});

it('lets the buyer accept an offer from the panel, which refunds at once', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::FULL_REFUND);

    panel($x['buyer'], $issue->fresh())
        ->callAction('accept')
        ->assertNotified('Offer accepted');

    expect($issue->fresh()->status)->toBe(IssueStatus::RESOLVED)
        ->and($issue->fresh()->refunded_cents)->toBe(6900)
        ->and(fakeGateway()->lastCall('refund')['amountCents'])->toBeNull();
});

it('lets the buyer decline from the panel', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::PARTIAL_REFUND, 500);

    panel($x['buyer'], $issue->fresh())->callAction('decline', ['note' => 'Too low.'])->assertNotified('Offer declined');

    expect($issue->fresh()->status)->toBe(IssueStatus::OPEN);
});

it('walks the return flow in the panel: tracking by the buyer, confirmation by the seller', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::RETURN_REFUND);
    issues()->acceptOffer($x['buyer'], $issue->fresh());

    panel($x['buyer'], $issue->fresh())
        ->assertSee('Send the goods back, then add the tracking number')
        ->assertSee('Add return tracking')
        ->callAction('returnTracking', ['carrier' => 'USPS', 'tracking_number' => '9400111', 'tracking_url' => ''])
        ->assertHasNoActionErrors()
        ->assertNotified('Return tracking saved');

    panel($x['seller'], $issue->fresh())
        ->assertSee('9400111')
        ->callAction('confirmReturn')
        ->assertNotified('Refund issued');

    expect($issue->fresh()->resolution)->toBe(IssueResolution::RETURN_REFUND);
});

it('shows "ask the team" only after the negotiation days, and escalates from the panel', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    panel($x['buyer'], $issue)->assertActionHidden('escalate')->assertSee('you can ask '.config('app.name').' to decide');

    $this->travel(3)->days();
    panel($x['buyer'], $issue->fresh())
        ->assertActionVisible('escalate')
        ->callAction('escalate', ['note' => 'No reply.'])
        ->assertNotified('Escalated');

    expect($issue->fresh()->status)->toBe(IssueStatus::ESCALATED);

    panel($x['buyer'], $issue->fresh())->assertSee('team is looking at this case')->assertActionHidden('escalate');
});

it('lets the buyer withdraw from the panel', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    panel($x['buyer'], $issue)->assertActionVisible('withdraw')->callAction('withdraw')->assertNotified('Report withdrawn');

    expect($issue->fresh()->resolution)->toBe(IssueResolution::WITHDRAWN);
    panel($x['seller'], $issue->fresh())->assertActionHidden('withdraw')->assertSee('Resolved');
});

it('hides the buyers actions from the seller and the other way round', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    issues()->makeOffer($x['seller'], $issue, IssueResolution::FULL_REFUND);

    panel($x['seller'], $issue->fresh())
        ->assertActionHidden('accept')->assertActionHidden('withdraw')->assertSee('Waiting for the buyer to answer your offer')->assertSee('Change my offer');
    panel($x['buyer'], $issue->fresh())
        ->assertActionHidden('offer')->assertDontSee('Change my offer')->assertDontSee('Make an offer')->assertActionVisible('accept')->assertSee('Accept the offer');
});

it('sends a reply from the panel, to the other side only', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    panel($x['seller'], $issue)
        ->fillForm(['body' => 'Could you send a photo of the cone?'])
        ->call('send')
        ->assertHasNoFormErrors();

    $last = $issue->fresh()->messages->last();
    expect($last->body)->toBe('Could you send a photo of the cone?')->and($last->author_id)->toBe($x['seller']->id)->and($last->internal)->toBeFalse();
    expect($x['buyer']->notifications()->where('data->kind', 'issue_message')->count())->toBe(1);

    panel($x['buyer'], $issue->fresh())->assertSee('Could you send a photo of the cone?');
});

it('sends a reply with a photo from the panel', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    panel($x['buyer'], $issue)
        ->fillForm(['body' => 'Here is the dent.', 'photos' => [UploadedFile::fake()->image('dent.jpg', 40, 30)]])
        ->call('send')
        ->assertHasNoFormErrors();

    expect($issue->fresh()->messages->last()->files())->toHaveCount(1);
});

it('does not render the panel for a stranger: 403', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    Livewire::actingAs(User::factory()->create())->test(OrderIssuePanel::class, ['issue' => $issue])->assertForbidden();
});

it('gives admins the internal note and keeps it from the buyer and seller', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);
    $admin = adminUser();

    panel($admin, $issue)
        ->assertSee('Message or internal note')
        ->fillForm(['body' => 'Seller has two earlier complaints.', 'internal' => true])
        ->call('send')
        ->assertHasNoFormErrors();

    panel($admin, $issue->fresh())->assertSee('Seller has two earlier complaints.')->assertSee('Internal note');
    panel($x['buyer'], $issue->fresh())->assertDontSee('Seller has two earlier complaints.');
    panel($x['seller'], $issue->fresh())->assertDontSee('Seller has two earlier complaints.');
});

// --- the shared issue page ----------------------------------------------------------------------------------------------

it('opens the shared issue page for the buyer, the seller and admins only', function () {
    $x = reportableOrder();
    $issue = reportProblem($x, IssueReason::DAMAGED, 'The cone is dented badly.');
    $url = '/dashboard/issues/'.$issue->id;

    $this->get($url)->assertRedirectContains('login');

    foreach ([User::factory()->create(), onboardedSeller()] as $stranger) {
        $this->actingAs($stranger)->get($url)->assertForbidden();
    }

    foreach ([$x['buyer'], $x['seller'], adminUser()] as $allowed) {
        $this->actingAs($allowed)->get($url)
            ->assertOk()
            ->assertSee('Problem: Arrived damaged')
            ->assertSee('The cone is dented badly.')
            ->assertSee($x['order']->number);
    }

    $this->actingAs($x['buyer'])->get('/dashboard/issues/999999')->assertNotFound();
});

it('lets the buyer contact the seller and the seller the buyer from the issue page', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    $this->actingAs($x['buyer'])->get($issue->url())->assertSee('Contact seller')->assertSee(url('/chatify/'.$x['seller']->id), false)
        ->assertSee('Back to order '.$x['order']->number);
    $this->actingAs($x['seller'])->get($issue->url())->assertSee('Contact buyer')->assertSee(url('/chatify/'.$x['buyer']->id), false)
        ->assertSee('Back to problem reports');
});

it('tells the seller on the issue page that the payout is on hold, with the amount', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    $this->actingAs($x['seller'])->get($issue->url())
        ->assertOk()
        ->assertSeeHtml('data-payout-state="held"')
        ->assertSee('Your payout')->assertSee('$63.00')->assertSee('On hold until this problem is resolved');
    $this->actingAs($x['buyer'])->get($issue->url())->assertDontSeeHtml('data-payout-state');
});

it('links admins from the issue page to the admin panel', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    $this->actingAs(adminUser())->get($issue->url())
        ->assertSee('Open in the admin panel')
        ->assertSee(App\Filament\Resources\OrderIssueResource::getUrl('view', ['record' => $issue]), false);
});

it('lists my problem reports as buyer and as seller, nobody elses', function () {
    $this->get('/dashboard/issues')->assertRedirectContains('login');

    $mine = reportableOrder();
    $myIssue = reportProblem($mine);
    $other = reportableOrder();
    $otherIssue = reportProblem($other);

    voltPage($this, $mine['buyer'], '/dashboard/issues', 'dashboard.issues')
        ->assertCanSeeTableRecords([$myIssue])->assertCanNotSeeTableRecords([$otherIssue]);
    voltPage($this, $mine['seller'], '/dashboard/issues', 'dashboard.issues')
        ->assertCanSeeTableRecords([$myIssue])->assertCanNotSeeTableRecords([$otherIssue]);

    $this->actingAs($mine['buyer'])->get('/dashboard/issues')->assertOk()->assertSee('Problem reports')->assertSee($mine['order']->number)->assertSee($myIssue->url(), false);
    $this->actingAs(User::factory()->create())->get('/dashboard/issues')->assertOk()->assertSee('No problem reports');
});

it('has a Problem Reports link in the dashboard sidebar', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard/library')->assertOk()->assertSee('Problem Reports')->assertSee(route('dashboard.issues'), false);
});

// --- sales and the selling dashboard ------------------------------------------------------------------------------------

it('shows the seller the problem on the sales page, with Contact buyer and the payout hold', function () {
    $x = reportableOrder();
    $issue = reportProblem($x);

    // the payout column is one the seller can switch on (it is hidden by default, like before)
    $page = voltPage($this, $x['seller'], '/dashboard/selling/orders', 'selling.orders')->set('toggledTableColumns.payout_cents', true);
    $page->assertCanSeeTableRecords([$x['sellerOrder']])
        ->assertTableActionVisible('problem', $x['sellerOrder'])
        ->assertTableActionVisible('contact', $x['sellerOrder'])
        ->assertSee('Open')                       // the issue badge
        ->assertSee('on hold: open problem');

    $html = $this->actingAs($x['seller'])->get('/dashboard/selling/orders')->assertOk();
    $html->assertSee($issue->url(), false)->assertSee(url('/chatify/'.$x['buyer']->id), false)->assertSee('14 days after you mark the parcel as shipped');
});

it('shows a payout date on the sales page and filters the sales with an open problem', function () {
    $x = reportableOrder();
    $other = reportableOrder();
    $page = voltPage($this, $x['seller'], '/dashboard/selling/orders', 'selling.orders')->set('toggledTableColumns.payout_cents', true);

    $page->assertSee('due '.$x['sellerOrder']->shipped_at->copy()->addDays(14)->format('M j, Y'));

    reportProblem($x);
    $page->filterTable('has_problem')->assertCanSeeTableRecords([$x['sellerOrder']]);
    voltPage($this, $other['seller'], '/dashboard/selling/orders', 'selling.orders')->filterTable('has_problem')->assertCanNotSeeTableRecords([$other['sellerOrder']]);
});

it('words the ship dialog with the payout delay', function () {
    $x = reportableOrder('paid');

    voltPage($this, $x['seller'], '/dashboard/selling/orders', 'selling.orders')
        ->mountTableAction('ship', $x['sellerOrder'])
        ->assertSee('14 days after you mark the parcel as shipped');
});

it('lists held and scheduled payouts on the selling dashboard', function () {
    $held = reportableOrder();
    reportProblem($held);

    $this->actingAs($held['seller'])->get('/dashboard/selling')
        ->assertOk()
        ->assertSee('Upcoming payouts')
        ->assertSeeHtml('data-payout-state="held"')
        ->assertSee('On hold')
        ->assertSee('1 on hold: open problem')
        ->assertSee($held['order']->number);

    $scheduled = reportableOrder();
    $due = $scheduled['sellerOrder']->shipped_at->copy()->addDays(14);
    $this->actingAs($scheduled['seller'])->get('/dashboard/selling')
        ->assertSeeHtml('data-payout-state="scheduled"')
        ->assertSee('Released on '.$due->format('M j, Y'))
        ->assertSee($due->format('M j'))
        ->assertSee('$63.00');

    $unshipped = reportableOrder('paid');
    $this->actingAs($unshipped['seller'])->get('/dashboard/selling')
        ->assertSeeHtml('data-payout-state="after_ship"')->assertSee('After shipping')->assertSee('Released after you mark the parcel as shipped');
});

it('shows nothing upcoming when everything is paid out', function () {
    config(['marketplace.physical_payout_trigger' => 'shipped']);
    $x = reportableOrder();

    $this->actingAs($x['seller'])->get('/dashboard/selling')->assertOk()->assertSee('Nothing is waiting to be paid out.');
});

it('keeps the sellers details modal honest about the payout', function () {
    $x = reportableOrder();

    voltPage($this, $x['seller'], '/dashboard/selling/orders', 'selling.orders')
        ->mountTableAction('details', $x['sellerOrder'])
        ->assertSee('Released on '.$x['sellerOrder']->shipped_at->copy()->addDays(14)->format('M j, Y'));
});

// --- notifications page -------------------------------------------------------------------------------------------------

it('shows the problem notifications on the /notifications page', function () {
    $x = reportableOrder();
    $issue = reportProblem($x, IssueReason::DAMAGED, 'The cone is dented badly.');
    issues()->makeOffer($x['seller'], $issue, IssueResolution::FULL_REFUND);

    $this->actingAs($x['seller'])->get('/notifications')->assertOk()
        ->assertSee('Problem reported')->assertSee($issue->url(), false);
    $this->actingAs($x['buyer'])->get('/notifications')->assertOk()
        ->assertSee('The seller made you an offer')->assertSee('a full refund');
});
