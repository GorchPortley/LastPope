<?php

use App\Enums\DiscrepancyKind;
use App\Enums\LedgerLink;
use App\Filament\Pages\StripeLedger;
use App\Filament\Resources\StripeDiscrepancyResource;
use App\Filament\Resources\StripeDiscrepancyResource\Pages\ListStripeDiscrepancies;
use App\Filament\Widgets\FinanceStats;
use App\Filament\Widgets\SellerBalances;
use App\Models\Order;
use App\Models\StripeBalanceTransaction;
use App\Models\StripeDiscrepancy;
use App\Models\User;
use App\Services\Marketplace\RefundService;
use App\Services\Marketplace\ShipmentService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| R6.2 admin: the Stripe ledger page (balance cards, transactions with filters, links, CSV, seller balances), the Reconciliation
| resource and the dashboard widget. Admins only, in the Finance group.
*/

beforeEach(function () {
    Notification::fake();
    config(['marketplace.fee_bps' => 1000, 'marketplace.physical_payout_trigger' => 'shipped', 'marketplace.stripe.secret_key' => 'sk_test_ledger']);
    $this->admin = adminUser();
});

/**
 * A shipped, paid-out order (charge 6900, transfer 6300, fee 230), a part-refund of it, a Wave charge, and a payout of the seller and
 * of the platform; everything synced.
 *
 * @return array{order: Order, seller: User, sellerOrder: App\Models\SellerOrder, account: string}
 */
function ledgerWorld(): array
{
    $x = chargedListingOrder();
    app(ShipmentService::class)->markShipped($x['seller'], $x['sellerOrder'], 'UPS', '1Z1', null);
    app(RefundService::class)->refundSellerOrderPartially(adminUser(), $x['sellerOrder']->fresh(), 1000, 'Scratched');
    fakeGateway()->recordSubscriptionCharge(1900, 'ch_wave_page');
    $account = stripeAccountOf($x['seller']);
    fakeGateway()->recordPayout($account, 2000, ['status' => 'in_transit', 'id' => 'po_seller_page']);
    fakeGateway()->recordPayout(null, 3000, ['status' => 'paid', 'id' => 'po_platform_page']);
    syncAllLedgers();

    return ['order' => $x['order'], 'seller' => $x['seller'], 'sellerOrder' => $x['sellerOrder']->fresh(), 'account' => $account];
}

// --- access ---------------------------------------------------------------------------------------------------------------

it('opens the ledger and the reconciliation for admins only, in the Finance group', function () {
    expect(StripeLedger::getNavigationGroup())->toBe('Finance')->and(StripeDiscrepancyResource::getNavigationGroup())->toBe('Finance')
        ->and(StripeLedger::getNavigationLabel())->toBe('Stripe ledger')->and(StripeDiscrepancyResource::getNavigationLabel())->toBe('Reconciliation');

    foreach (['/admin/stripe-ledger', '/admin/reconciliation'] as $url) {
        $this->actingAs($this->admin)->get($url)->assertOk();
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs(onboardedSeller())->get($url)->assertForbidden();
    }

    auth()->logout();
    $this->get('/admin/stripe-ledger')->assertRedirect();
    $this->get('/admin/reconciliation')->assertRedirect();
});

it('keeps the finance widgets from everybody but admins', function () {
    $this->actingAs(User::factory()->create());
    expect(FinanceStats::canView())->toBeFalse()->and(SellerBalances::canView())->toBeFalse();

    $this->actingAs($this->admin);
    expect(FinanceStats::canView())->toBeTrue()->and(SellerBalances::canView())->toBeTrue();
});

// --- balance cards ------------------------------------------------------------------------------------------------------------

it('says it has not read Stripe yet before the first sync', function () {
    Livewire::actingAs($this->admin)->test(StripeLedger::class)
        ->assertSee('Not read from Stripe yet')->assertSee('stripe:ledger-sync --all')->assertSee('Nothing read from Stripe yet')->assertSeeHtml('data-ledger-no-balance');
});

it('shows the platform balance with the time of the last sync', function () {
    $this->travelTo(now()->startOfSecond());
    fakeGateway()->recordSubscriptionCharge(10000, 'ch_wave_a', ['fee' => 0, 'created' => now()->subDays(5)]);
    fakeGateway()->recordSubscriptionCharge(2500, 'ch_wave_b', ['fee' => 0]);
    fakeGateway()->recordPayout(null, 3000, ['status' => 'in_transit', 'created' => now()->subHour()]);
    syncLedger();
    $this->travel(7)->minutes();

    Livewire::actingAs($this->admin)->test(StripeLedger::class)
        ->assertSeeHtml('data-balance-card="available"')->assertSeeHtml('data-balance-card="pending"')->assertSeeHtml('data-balance-card="in-transit"')
        ->assertSeeInOrder(['Available', '$70.00', 'Pending', '$25.00', 'On its way to the bank', '$30.00'])
        ->assertSee('Read from Stripe')->assertSee('7 minutes ago')->assertSee('test mode')
        ->assertDontSeeHtml('data-ledger-error');
});

it('shows a balance per currency', function () {
    fakeGateway()->recordSubscriptionCharge(5000, 'ch_wave_usd', ['fee' => 0, 'created' => now()->subDays(5)]);
    config(['marketplace.currency' => 'eur']);
    fakeGateway()->recordSubscriptionCharge(7000, 'ch_wave_eur', ['fee' => 0, 'created' => now()->subDays(5)]);
    config(['marketplace.currency' => 'usd']);
    syncLedger();

    $page = Livewire::actingAs($this->admin)->test(StripeLedger::class)
        ->assertSeeHtml('data-balance-currency="usd"')->assertSeeHtml('data-balance-currency="eur"')->assertSee('USD')->assertSee('EUR');
    expect($page->html())->toContain('$50.00')->toContain('€70.00');
});

it('shows a failing sync, for the platform and for a seller account', function () {
    $x = ledgerWorld();
    fakeGateway()->failOn('listBalanceTransactions', 2);
    expect(fn () => syncLedger())->toThrow(App\Services\Payments\Exceptions\PaymentGatewayException::class);
    expect(fn () => syncLedger($x['account']))->toThrow(App\Services\Payments\Exceptions\PaymentGatewayException::class);

    Livewire::actingAs($this->admin)->test(StripeLedger::class)
        ->assertSeeHtml('data-ledger-error')->assertSee('The last attempt failed')
        ->assertSeeHtml('data-ledger-error-account="'.$x['account'].'"');
});

// --- the transactions ---------------------------------------------------------------------------------------------------------------

it('lists the transactions of the platform and the sellers, each with its order', function () {
    $x = ledgerWorld();
    $rows = StripeBalanceTransaction::currentMode()->get();

    $page = Livewire::actingAs($this->admin)->test(StripeLedger::class)
        ->assertCanSeeTableRecords($rows)
        ->assertSee('Payment')->assertSee('Transfer to seller')->assertSee('Transfer received')->assertSee('Refund')->assertSee('Payout')
        ->assertSee($x['order']->number)->assertSee('Platform')->assertSee($x['seller']->sellerProfile->shop_name)
        ->assertSee('Subscription creation');

    // the order links to the order in the admin panel
    expect($page->html())->toContain(App\Filament\Resources\OrderResource::getUrl('view', ['record' => $x['order']]));
});

it('filters the transactions by type, account, date and link', function () {
    $x = ledgerWorld();
    fakeGateway()->recordTransfer('tr_orphan_page', $x['account'], 900, null, 'SDL-NOTHING', ['seller_order_id' => '5']);
    resyncLedgers();
    $all = StripeBalanceTransaction::currentMode()->get();
    $page = Livewire::actingAs($this->admin)->test(StripeLedger::class);

    $page->filterTable('type', ['charge'])
        ->assertCanSeeTableRecords($all->where('type', 'charge'))->assertCanNotSeeTableRecords($all->where('type', '!=', 'charge'))
        ->resetTableFilters();

    $page->filterTable('account', 'platform')
        ->assertCanSeeTableRecords($all->whereNull('account'))->assertCanNotSeeTableRecords($all->whereNotNull('account'))
        ->filterTable('account', $x['account'])
        ->assertCanSeeTableRecords($all->where('account', $x['account']))->assertCanNotSeeTableRecords($all->whereNull('account'))
        ->resetTableFilters();

    $page->filterTable('link_status', LedgerLink::UNLINKED->value)
        ->assertCanSeeTableRecords($all->where('link_status', LedgerLink::UNLINKED))->assertCanNotSeeTableRecords($all->where('link_status', LedgerLink::LINKED))
        ->resetTableFilters();
    expect($all->where('link_status', LedgerLink::UNLINKED)->count())->toBeGreaterThan(0)->and($all->where('link_status', LedgerLink::LINKED)->count())->toBeGreaterThan(0);

    // dates: move one line to last year
    $old = $all->firstWhere('source_id', 'ch_wave_page');
    $old->forceFill(['stripe_created_at' => now()->subYear()])->save();
    $page->filterTable('date', ['from' => now()->subDays(30)->toDateString(), 'until' => null])
        ->assertCanNotSeeTableRecords([$old->fresh()])->assertCanSeeTableRecords($all->where('id', '!=', $old->id))
        ->resetTableFilters()
        ->filterTable('date', ['from' => null, 'until' => now()->subMonths(6)->toDateString()])
        ->assertCanSeeTableRecords([$old->fresh()])->assertCanNotSeeTableRecords($all->where('id', '!=', $old->id));
});

it('searches the transactions by order number', function () {
    $x = ledgerWorld();
    $mine = StripeBalanceTransaction::where('order_id', $x['order']->id)->get();
    $others = StripeBalanceTransaction::whereNull('order_id')->get();

    Livewire::actingAs($this->admin)->test(StripeLedger::class)
        ->searchTable($x['order']->number)
        ->assertCanSeeTableRecords($mine)->assertCanNotSeeTableRecords($others);
});

it('only shows the lines of the current Stripe mode', function () {
    ledgerWorld();
    config(['marketplace.stripe.secret_key' => 'sk_live_ledger']);

    Livewire::actingAs($this->admin)->test(StripeLedger::class)
        ->assertCountTableRecords(0)->assertSee('live mode');
});

it('links each line to Stripe, in test mode under /test/', function () {
    $x = ledgerWorld();
    $charge = StripeBalanceTransaction::where('type', 'charge')->where('order_id', $x['order']->id)->firstOrFail();
    $transfer = StripeBalanceTransaction::where('type', 'transfer')->firstOrFail();
    $payout = StripeBalanceTransaction::where('type', 'payout')->whereNull('account')->firstOrFail();
    $received = StripeBalanceTransaction::where('type', 'payment')->firstOrFail();

    expect($charge->stripeUrl())->toBe('https://dashboard.stripe.com/test/payments/'.$charge->charge_id)
        ->and($transfer->stripeUrl())->toBe('https://dashboard.stripe.com/test/connect/transfers/'.$transfer->transfer_id)
        ->and($payout->stripeUrl())->toBe('https://dashboard.stripe.com/test/payouts/po_platform_page')
        ->and($received->stripeUrl())->toBe('https://dashboard.stripe.com/test/connect/accounts/'.$x['account'].'/activity');

    // a row from live mode links to the live dashboard
    $charge->forceFill(['livemode' => true])->save();
    expect($charge->fresh()->stripeUrl())->toBe('https://dashboard.stripe.com/payments/'.$charge->charge_id);

    Livewire::actingAs($this->admin)->test(StripeLedger::class)
        ->assertTableActionHasUrl('stripe', $transfer->stripeUrl(), $transfer);
});

// --- sync now and export --------------------------------------------------------------------------------------------------------------

it('reads Stripe on "Sync now" and has the sellers read in the background', function () {
    $x = chargedListingOrder();
    app(ShipmentService::class)->markShipped($x['seller'], $x['sellerOrder'], 'UPS', '1Z1', null);
    expect(StripeBalanceTransaction::count())->toBe(0);

    Livewire::actingAs($this->admin)->test(StripeLedger::class)
        ->callAction('sync')
        ->assertNotified('Synced with Stripe');

    // the platform inline; the seller account was queued (the queue runs inline in tests)
    expect(StripeBalanceTransaction::forAccount(null)->count())->toBe(2)
        ->and(StripeBalanceTransaction::forAccount(stripeAccountOf($x['seller']))->count())->toBe(1);
});

it('shows a Stripe error on "Sync now" instead of failing', function () {
    fakeGateway()->failOn('listBalanceTransactions');

    Livewire::actingAs($this->admin)->test(StripeLedger::class)
        ->callAction('sync')
        ->assertNotified('Could not read Stripe');
});

it('exports the transactions as CSV, the filtered ones, money in dollars', function () {
    $x = ledgerWorld();
    $page = Livewire::actingAs($this->admin)->test(StripeLedger::class);

    $csv = function ($component): array {
        ob_start();
        $component->instance()->exportCsv()->sendContent();

        return array_map('str_getcsv', array_filter(explode("\n", ob_get_clean())));
    };

    $page->callAction('export')->assertFileDownloaded('stripe-ledger-'.now()->format('Y-m-d').'.csv');

    $rows = $csv($page);
    $header = array_shift($rows);
    expect($header)->toBe(['Date (UTC)', 'Account', 'Type', 'Reporting category', 'Amount', 'Fee', 'Net', 'Currency', 'Available on (UTC)', 'Status', 'Order', 'Link', 'Description', 'Stripe transaction', 'Stripe object'])
        ->and($rows)->toHaveCount(StripeBalanceTransaction::currentMode()->count());
    $charge = collect($rows)->firstWhere(14, $x['order']->stripe_charge_id);
    expect($charge)->toMatchArray([1 => 'Platform', 2 => 'Payment', 4 => '69.00', 5 => '2.30', 6 => '66.70', 7 => 'USD', 10 => $x['order']->number, 11 => 'Linked to an order']);
    $transfer = collect($rows)->firstWhere(2, 'Transfer to seller');
    expect($transfer[4])->toBe('-63.00');

    // the filter is respected
    $page->filterTable('account', $x['account']);
    $filtered = $csv($page);
    array_shift($filtered);
    expect($filtered)->toHaveCount(StripeBalanceTransaction::where('account', $x['account'])->count())->and(collect($filtered)->pluck(1)->unique()->all())->toBe([$x['seller']->sellerProfile->shop_name]);
});

it('keeps spreadsheet formulas out of the export', function () {
    fakeGateway()->recordSubscriptionCharge(1000, 'ch_formula', ['description' => '=HYPERLINK("http://evil.example","pay")']);
    syncLedger();
    $page = Livewire::actingAs($this->admin)->test(StripeLedger::class);

    ob_start();
    $page->instance()->exportCsv()->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain("\"'=HYPERLINK")->and($csv)->not->toContain(',"=HYPERLINK');
});

// --- seller balances ----------------------------------------------------------------------------------------------------------------------

it('lists the sellers with their Stripe balance, payouts and the money the site still holds', function () {
    $x = ledgerWorld();
    $other = chargedListingOrder();      // a second seller whose order is not shipped yet: the site holds the payout
    syncAllLedgers();
    $profile = $x['seller']->sellerProfile;

    Livewire::actingAs($this->admin)->test(SellerBalances::class)
        ->assertCanSeeTableRecords([$profile, $other['seller']->sellerProfile])
        ->assertSee($profile->shop_name)->assertSee($profile->stripe_account_id)
        ->assertSee('Held by the site')
        // the shipped seller's payout of 2000 is on its way; the other seller's order waits for the parcel: 6300 held
        ->assertSee('$20.00')->assertSee('$63.00');

    // demo sellers have made-up accounts and are left out
    $demo = App\Models\SellerProfile::where('stripe_account_id', 'like', 'acct_demo%')->first();
    expect($demo)->not->toBeNull();
    Livewire::actingAs($this->admin)->test(SellerBalances::class)->assertCanNotSeeTableRecords([$demo]);
});

it('shows the seller balances below the transactions', function () {
    ledgerWorld();

    $this->actingAs($this->admin)->get('/admin/stripe-ledger')->assertOk()->assertSeeInOrder(['Transactions', 'Seller balances']);
});

// --- the reconciliation resource ----------------------------------------------------------------------------------------------------------

function discrepancy(array $attributes = []): StripeDiscrepancy
{
    static $n = 0;
    $n++;

    return StripeDiscrepancy::create($attributes + [
        'kind' => DiscrepancyKind::CHARGE_AMOUNT, 'key' => 'order:'.$n, 'livemode' => false, 'stripe_id' => 'ch_diff_'.$n,
        'expected_cents' => 10000, 'actual_cents' => 9000, 'currency' => 'usd', 'details' => 'Order SDL-X: for $100.00 here, $90.00 at Stripe.',
        'first_seen_at' => now()->subDay(), 'last_seen_at' => now(),
    ]);
}

it('lists the open differences first, and the closed ones under their own tab', function () {
    $open = discrepancy();
    $fixed = discrepancy(['resolved_at' => now(), 'resolution' => 'auto', 'note' => 'Fixed: a later check no longer finds this difference.']);
    $closed = discrepancy(['resolved_at' => now(), 'resolution' => 'manual', 'resolved_by' => $this->admin->id, 'note' => 'Refunded by hand.']);
    $live = discrepancy(['livemode' => true]);

    Livewire::actingAs($this->admin)->test(ListStripeDiscrepancies::class)
        ->assertSet('activeTab', 'open')
        ->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$fixed, $closed, $live])
        ->assertSee('Payment amount differs')->assertSee('$100.00')->assertSee('$90.00')
        ->set('activeTab', 'resolved')
        ->assertCanSeeTableRecords([$fixed, $closed])->assertCanNotSeeTableRecords([$open])
        ->assertSee('Fixed')->assertSee($this->admin->name)
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$open, $fixed, $closed])->assertCanNotSeeTableRecords([$live]);
});

it('filters the differences by kind and links the order and Stripe', function () {
    $order = Order::factory()->paid()->create();
    $amount = discrepancy(['order_id' => $order->id]);
    $payout = discrepancy(['kind' => DiscrepancyKind::PAYOUT_FAILED, 'key' => 'payout:po_1', 'stripe_id' => 'po_1', 'order_id' => null]);
    $dispute = discrepancy(['kind' => DiscrepancyKind::DISPUTE_NO_CASE, 'key' => 'dispute:du_1', 'stripe_id' => 'du_1']);

    $page = Livewire::actingAs($this->admin)->test(ListStripeDiscrepancies::class)
        ->filterTable('kind', [DiscrepancyKind::PAYOUT_FAILED->value])
        ->assertCanSeeTableRecords([$payout])->assertCanNotSeeTableRecords([$amount, $dispute])
        ->resetTableFilters()
        ->assertTableActionHasUrl('open', 'https://dashboard.stripe.com/test/payouts/po_1', $payout)
        ->assertTableActionHasUrl('open', 'https://dashboard.stripe.com/test/disputes/du_1', $dispute)
        ->assertTableActionHasUrl('open', 'https://dashboard.stripe.com/test/payments/'.$amount->stripe_id, $amount);
    expect($page->html())->toContain(App\Filament\Resources\OrderResource::getUrl('view', ['record' => $order]));
});

it('closes a difference with a note, and opens it again', function () {
    $diff = discrepancy();

    Livewire::actingAs($this->admin)->test(ListStripeDiscrepancies::class)
        ->callTableAction('resolve', $diff, data: ['note' => ''])->assertHasTableActionErrors(['note' => 'required']);
    expect($diff->fresh()->isOpen())->toBeTrue();

    Livewire::actingAs($this->admin)->test(ListStripeDiscrepancies::class)
        ->callTableAction('resolve', $diff, data: ['note' => 'The buyer paid the rest by bank transfer.'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Difference closed');

    $diff->refresh();
    expect($diff->isOpen())->toBeFalse()->and($diff->resolution)->toBe('manual')->and($diff->resolved_by)->toBe($this->admin->id)
        ->and($diff->note)->toBe('The buyer paid the rest by bank transfer.')->and($diff->resolved_at)->not->toBeNull();

    Livewire::actingAs($this->admin)->test(ListStripeDiscrepancies::class)
        ->set('activeTab', 'resolved')
        ->assertTableActionHidden('resolve', $diff)
        ->callTableAction('reopen', $diff)
        ->assertNotified('Difference opened again');

    expect($diff->fresh()->isOpen())->toBeTrue()->and($diff->fresh()->note)->toBeNull();
});

it('checks on demand and tells how it went', function () {
    Livewire::actingAs($this->admin)->test(ListStripeDiscrepancies::class)
        ->callAction('check')
        ->assertNotified('Not checked');      // the ledger was never read

    ledgerWorld();
    $this->travel(2)->hours();
    syncAllLedgers();
    $fixable = discrepancy(['kind' => DiscrepancyKind::PAYOUT_FAILED, 'key' => 'payout:po_gone']);

    Livewire::actingAs($this->admin)->test(ListStripeDiscrepancies::class)
        ->callAction('check')
        ->assertNotified('Everything agrees');

    // nothing is mailed by the button, and the difference nobody found again is closed
    Notification::assertNotSentTo($this->admin, App\Notifications\StripeDifferencesFound::class);
    expect($fixable->fresh()->resolution)->toBe('auto');
});

it('counts the open differences in the navigation badge', function () {
    expect(StripeDiscrepancyResource::getNavigationBadge())->toBeNull();

    discrepancy();
    discrepancy();
    discrepancy(['resolved_at' => now(), 'resolution' => 'auto']);
    discrepancy(['livemode' => true]);

    expect(StripeDiscrepancyResource::getNavigationBadge())->toBe('2')->and(StripeDiscrepancyResource::openCount())->toBe(2);
});

// --- the dashboard widget ----------------------------------------------------------------------------------------------------------------------

it('shows the open differences, the balance and the last sync on the admin dashboard', function () {
    ledgerWorld();
    discrepancy();
    discrepancy();

    Livewire::actingAs($this->admin)->test(FinanceStats::class)
        ->assertSee('Open Stripe differences')->assertSee('2')->assertSee('the shop and Stripe disagree')
        ->assertSee('Stripe balance')->assertSee('Ledger last read');

    // the dashboard loads its widgets afterwards: it is on the panel and the page opens
    expect(Filament\Facades\Filament::getPanel('admin')->getWidgets())->toContain(FinanceStats::class);
    $this->actingAs($this->admin)->get('/admin')->assertOk();
});

it('says everything agrees when nothing is open', function () {
    Livewire::actingAs($this->admin)->test(FinanceStats::class)
        ->assertSee('the shop and Stripe agree')->assertSee('never')->assertSee('not read from Stripe yet');
});
