<?php

use App\Enums\SupplierOrderStatus;
use App\Filament\Pages\Margins;
use App\Filament\Widgets\StoreFulfilment;
use App\Models\Order;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Services\Marketplace\MarginReport;
use App\Services\Marketplace\PurchaseOrderService;
use App\Services\Marketplace\ShipmentService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| R3 part 2, admin: the Margins page (date range, totals cards, the orders, CSV) and the "Store fulfilment" dashboard widget.
*/

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    // far from the demo data, so the report of "this month" holds only what a test creates
    $this->travelTo(now()->addYears(2)->startOfMonth()->addDays(14)->setTime(12, 0));
    config(['marketplace.fee_bps' => 1000, 'marketplace.stripe_fee_bps' => 290, 'marketplace.stripe_fee_fixed_cents' => 30, 'marketplace.min_margin_bps' => 1500]);
    $this->admin = adminUser();
    $this->buyer = User::factory()->create();
    $this->acme = supplier(['dropship_fee_cents' => 250, 'shipping_domestic_cents' => 899, 'additional_item_shipping_cents' => 300]);
});

/**
 * Own stock (8000, cost 5000, shipping 900) + 2 Acme units (10000, cost 6000; shipping 899 + 300), paid now:
 * items 28000, shipping 2099, revenue 30099, goods 17000, supplier shipping 1000, fee 250, Stripe 903, margin 10946 (36.4 %).
 */
function marginsOrder(object $test): Order
{
    $stock = storeListing(['price_cents' => 8000, 'cost_cents' => 5000, 'quantity' => 5, 'shipping_domestic_cents' => 900, 'additional_item_shipping_cents' => 0], null, $test->admin);
    $dropped = dropshipListing($test->acme, ['price_cents' => 10000, 'cost_cents' => 6000, 'supplier_stock' => 9], $test->admin);
    $order = paidOrderOf($test->buyer, [[$stock, 1], [$dropped, 2]]);
    app(PurchaseOrderService::class)->recordSupplierCosts($test->admin, purchaseOrderOf($order), 1000);

    return $order;
}

// --- the margins page ------------------------------------------------------------------------------------------

it('opens the Margins page for admins only, in the Store group', function () {
    expect(Margins::getNavigationGroup())->toBe('Store');

    $this->actingAs($this->admin)->get('/admin/margins')->assertOk()->assertSee('Margins')->assertSee('This month')->assertSee('Revenue');
    $this->actingAs($this->buyer)->get('/admin/margins')->assertForbidden();
    $this->actingAs(onboardedSeller())->get('/admin/margins')->assertForbidden();

    auth()->logout();
    $this->get('/admin/margins')->assertRedirect();
});

it('shows the totals cards and the order row for a known fixture', function () {
    $order = marginsOrder($this);

    $page = Livewire::actingAs($this->admin)->test(Margins::class)
        ->assertSet('data.preset', 'this_month')
        ->assertSee('$300.99')            // revenue: items 280.00 + shipping 20.99
        ->assertSee('$182.50')            // costs: goods 170.00 + supplier shipping 10.00 + fee 2.50
        ->assertSee('$9.03')              // Stripe, estimated
        ->assertSee('$109.46')            // margin
        ->assertSee('36.4 %')
        ->assertSeeHtml('data-margin-card="revenue"')->assertSeeHtml('data-margin-card="costs"')->assertSeeHtml('data-margin-card="stripe-fees"')
        ->assertSeeHtml('data-margin-card="refunds"')->assertSeeHtml('data-margin-card="margin"')->assertSeeHtml('data-margin-card="margin-percent"')
        ->assertSeeHtml('data-margin-row="'.$order->number.'"')
        ->assertSeeHtml('data-margin-total-row')
        ->assertDontSee('costs pending');

    // every number on the page is the report's
    $report = app(MarginReport::class)->forRange(now()->startOfMonth(), now());
    expect($report['totals']->revenueCents())->toBe(30099)->and($report['totals']->marginCents())->toBe(10946);
});

it('marks an order whose supplier shipping is not entered yet', function () {
    $stock = storeListing(['price_cents' => 8000, 'cost_cents' => 5000, 'quantity' => 5], null, $this->admin);
    $dropped = dropshipListing($this->acme, ['price_cents' => 10000, 'cost_cents' => 6000, 'supplier_stock' => 9], $this->admin);
    paidOrderOf($this->buyer, [[$stock, 1], [$dropped, 1]]);

    Livewire::actingAs($this->admin)->test(Margins::class)->assertSee('costs pending');
});

it('subtracts refunds and shows the margin in red when it is a loss', function () {
    $order = marginsOrder($this);
    app(App\Services\Marketplace\RefundService::class)->refundSellerOrder($this->admin, $order->sellerOrders->first(), 'Changed mind');

    $page = Livewire::actingAs($this->admin)->test(Margins::class)
        ->assertSeeHtml('data-margin-card="refunds"')
        ->assertSee('$300.99');           // refunded in full; also the revenue

    // nothing was kept: no percentage, and the margin is negative (the own-stock unit and Stripe's fee are still ours)
    expect($page->html())->toContain('text-danger-600');
});

it('switches the period with the presets and with a custom range', function () {
    $order = marginsOrder($this);
    $page = Livewire::actingAs($this->admin)->test(Margins::class)->assertSeeHtml('data-margin-row="'.$order->number.'"');

    // last month: nothing was paid then
    $page->set('data.preset', 'last_month')
        ->assertSet('data.from', now()->subMonthNoOverflow()->startOfMonth()->toDateString())
        ->assertSet('data.until', now()->subMonthNoOverflow()->endOfMonth()->toDateString())
        ->assertSee('No paid store orders in this period.')->assertDontSeeHtml('data-margin-row="'.$order->number.'"');

    $page->set('data.preset', 'last_30_days')
        ->assertSet('data.from', now()->subDays(29)->toDateString())->assertSet('data.until', now()->toDateString())
        ->assertSeeHtml('data-margin-row="'.$order->number.'"');

    // custom: a day range that misses the order, then one that holds it (entered the wrong way round, it is swapped)
    $page->set('data.preset', 'custom')
        ->set('data.from', now()->subDays(10)->toDateString())->set('data.until', now()->subDays(5)->toDateString())
        ->assertSee('No paid store orders in this period.')
        ->set('data.from', now()->addDay()->toDateString())->set('data.until', now()->subDays(2)->toDateString())
        ->assertSeeHtml('data-margin-row="'.$order->number.'"');

    expect(Margins::presetRange('this_month')[0]->toDateString())->toBe(now()->startOfMonth()->toDateString());
});

it('downloads the report of the shown period as CSV', function () {
    $order = marginsOrder($this);
    $from = now()->startOfMonth();
    $csv = app(MarginReport::class)->toCsv($from, now());

    Livewire::actingAs($this->admin)->test(Margins::class)
        ->callAction('downloadCsv')
        ->assertFileDownloaded('margins-'.$from->toDateString().'-to-'.now()->toDateString().'.csv', $csv);

    expect($csv)->toContain('Order,Paid,Items')->toContain($order->number)->toContain('300.99')->toContain('Total (1 orders)');
});

// --- the dashboard widget --------------------------------------------------------------------------------------

it('shows the store fulfilment numbers on the admin dashboard', function () {
    SupplierOrder::query()->delete();
    $before = StoreFulfilment::parcelsAwaitingShipment();

    // the widget is on the dashboard (stats widgets load lazily, so the page holds the component, the numbers come with it)
    $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee('app.filament.widgets.store-fulfilment', false);

    Livewire::actingAs($this->admin)->test(StoreFulfilment::class)
        ->assertSee('Store fulfilment')->assertSee('Purchase orders to send')->assertSee('Purchase orders with a problem')
        ->assertSee('Store parcels to ship')->assertSee('Suppliers with feed errors');

    // two purchase orders to send (Acme and a portal supplier), one store parcel to ship
    $bolt = supplier(['order_email' => null]);
    $stock = storeListing(['price_cents' => 8000, 'quantity' => 5], null, $this->admin);
    $order = paidOrderOf($this->buyer, [[$stock, 1], [dropshipListing($this->acme, ['supplier_stock' => 4], $this->admin), 1], [dropshipListing($bolt, ['supplier_stock' => 4], $this->admin), 1]]);
    $pos = app(PurchaseOrderService::class);

    expect(StoreFulfilment::parcelsAwaitingShipment())->toBe($before + 1);

    Livewire::actingAs($this->admin)->test(StoreFulfilment::class)
        ->assertSee('Purchase orders to send')->assertSeeInOrder(['Purchase orders to send', '2'])
        ->assertSeeHtml(SupplierOrderResourceUrl('to_send'));

    // one sent, one flagged; the own-stock parcel shipped; a supplier whose feed failed
    $pos->send($this->admin, purchaseOrderOf($order, $this->acme));
    $pos->flagProblem(purchaseOrderOf($order, $bolt), 'Back-ordered', $this->admin);
    app(ShipmentService::class)->shipItems($this->admin, $order->sellerOrders->first(), null, 'UPS', '1ZSTORE');
    $this->acme->forceFill(['feed_url' => 'https://feeds.example.test/x.csv', 'feed_last_error' => 'The feed returned HTTP 500.'])->save();

    expect(SupplierOrder::query()->toSend()->count())->toBe(0)
        ->and(SupplierOrder::query()->status(SupplierOrderStatus::PROBLEM)->count())->toBe(1)
        ->and(StoreFulfilment::parcelsAwaitingShipment())->toBe($before)
        ->and(StoreFulfilment::suppliersWithFeedErrors())->toBe(1);

    Livewire::actingAs($this->admin)->test(StoreFulfilment::class)
        ->assertSee('nothing waiting')->assertSee('cancel, confirm or ship them')->assertSee('the last stock sync failed');
});

it('hides the store fulfilment widget from everybody but admins', function () {
    $this->actingAs($this->buyer);
    expect(StoreFulfilment::canView())->toBeFalse();

    $this->actingAs($this->admin);
    expect(StoreFulfilment::canView())->toBeTrue();
});

function SupplierOrderResourceUrl(string $tab): string
{
    return e(App\Filament\Resources\SupplierOrderResource::getUrl('index', ['activeTab' => $tab]));
}
