<?php

use App\Enums\SellerOrderStatus;
use App\Enums\SupplierOrderStatus;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\Resources\OrderResource\RelationManagers\PurchaseOrdersRelationManager;
use App\Filament\Resources\SupplierOrderResource;
use App\Filament\Resources\SupplierOrderResource\Pages\ListSupplierOrders;
use App\Filament\Resources\SupplierOrderResource\Pages\ViewSupplierOrder;
use App\Mail\PurchaseOrderMail;
use App\Models\Order;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Notifications\OrderShipped;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| R3 part 2, admin: the purchase order queue (tabs, badge), the purchase order page with its actions (each one calls
| PurchaseOrderService with the signed-in admin), and the purchase orders on the store order.
*/

beforeEach(function () {
    config(['marketplace.store_name' => 'SDLabs', 'marketplace.store_email' => 'store@sdlabs.test', 'marketplace.fee_bps' => 1000]);
    $this->admin = adminUser();
    $this->buyer = User::factory()->create();
    $this->acme = supplier(['name' => 'Acme Wholesale', 'order_email' => 'orders@acme.example.test', 'lead_time_min_days' => 5, 'lead_time_max_days' => 8]);
    $this->bolt = supplier(['name' => 'Bolt Portal', 'order_email' => null]);
});

/** Own stock + two Acme lines + one Bolt line, paid: two purchase orders (Acme, Bolt), both pending. */
function storeAdminOrder(object $test): Order
{
    Mail::fake();
    Notification::fake();
    $stock = storeListing(['price_cents' => 8000, 'quantity' => 5, 'shipping_domestic_cents' => 900, 'additional_item_shipping_cents' => 0, 'title' => 'Stocked Woofer 8'], null, $test->admin);
    $d1 = dropshipListing($test->acme, ['price_cents' => 10000, 'cost_cents' => 6000, 'supplier_sku' => 'ACM-100', 'supplier_stock' => 10, 'title' => 'Dropped Midrange 5'], $test->admin);
    $d2 = dropshipListing($test->bolt, ['price_cents' => 3000, 'cost_cents' => 1800, 'supplier_sku' => 'BLT-300', 'title' => 'Dropped Tweeter 1'], $test->admin);

    return paidOrderOf($test->buyer, [[$stock, 1], [$d1, 2], [$d2, 1]]);
}

// --- access ---------------------------------------------------------------------------------------------------

it('opens the purchase order queue and a purchase order for admins only', function () {
    $order = storeAdminOrder($this);
    $po = purchaseOrderOf($order, $this->acme);

    $this->actingAs($this->admin)->get('/admin/purchase-orders')->assertOk()->assertSee('Purchase orders')->assertSee($po->number);
    $this->actingAs($this->admin)->get('/admin/purchase-orders/'.$po->getKey())->assertOk()->assertSee($po->number)->assertSee('ACM-100');

    foreach ([$this->buyer, onboardedSeller()] as $other) {
        $this->actingAs($other)->get('/admin/purchase-orders')->assertForbidden();
        $this->actingAs($other)->get('/admin/purchase-orders/'.$po->getKey())->assertForbidden();
    }

    auth()->logout();
    $this->get('/admin/purchase-orders')->assertRedirect();
});

it('is in the Store navigation group and the badge counts the purchase orders waiting to be sent', function () {
    SupplierOrder::query()->delete(); // the demo data has purchase orders to send too
    expect(SupplierOrderResource::getNavigationGroup())->toBe('Store')
        ->and(SupplierOrderResource::getNavigationBadge())->toBeNull();

    $order = storeAdminOrder($this);

    $this->actingAs($this->admin);
    expect(SupplierOrderResource::getNavigationBadge())->toBe('2')
        ->and(SupplierOrderResource::getNavigationBadgeColor())->toBe('warning');

    app(App\Services\Marketplace\PurchaseOrderService::class)->send($this->admin, purchaseOrderOf($order, $this->acme));
    expect(SupplierOrderResource::getNavigationBadge())->toBe('1');

    $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee('Purchase orders');
});

// --- the queue ------------------------------------------------------------------------------------------------

it('lists the purchase orders in tabs by status, the ones to send first', function () {
    SupplierOrder::query()->delete(); // the demo data has some of every status
    $pending = SupplierOrder::factory()->create();
    $sent = SupplierOrder::factory()->sent()->create();
    $confirmed = SupplierOrder::factory()->confirmed()->create();
    $shipped = SupplierOrder::factory()->shipped()->create();
    $problem = SupplierOrder::factory()->problem()->create();
    $cancelled = SupplierOrder::factory()->cancelled()->create();
    $all = [$pending, $sent, $confirmed, $shipped, $problem, $cancelled];

    $list = Livewire::actingAs($this->admin)->test(ListSupplierOrders::class)->assertSet('activeTab', 'to_send');

    expect(array_keys($list->instance()->getTabs()))->toBe(['to_send', 'sent', 'confirmed', 'shipped', 'problem', 'cancelled', 'all']);

    $list->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$sent, $confirmed, $shipped, $problem, $cancelled]);

    foreach (['sent' => $sent, 'confirmed' => $confirmed, 'shipped' => $shipped, 'problem' => $problem, 'cancelled' => $cancelled] as $tab => $record) {
        $list->set('activeTab', $tab)->assertCanSeeTableRecords([$record])->assertCanNotSeeTableRecords(array_filter($all, fn ($po) => ! $po->is($record)));
    }

    $list->set('activeTab', 'all')->assertCanSeeTableRecords($all);
});

it('filters the queue by supplier and sends a pending purchase order from the row', function () {
    $order = storeAdminOrder($this);
    $acmePo = purchaseOrderOf($order, $this->acme);
    $boltPo = purchaseOrderOf($order, $this->bolt);

    $list = Livewire::actingAs($this->admin)->test(ListSupplierOrders::class)
        ->filterTable('supplier_id', $this->bolt->getKey())
        ->assertCanSeeTableRecords([$boltPo])->assertCanNotSeeTableRecords([$acmePo])
        ->resetTableFilters();

    // the supplier without an order e-mail cannot be sent to: no quick action; the other one can
    $list->assertTableActionHidden('send', $boltPo)->assertTableActionVisible('send', $acmePo)
        ->callTableAction('send', $acmePo)
        ->assertNotified('Purchase order sent');

    Mail::assertQueued(PurchaseOrderMail::class, fn (PurchaseOrderMail $mail) => $mail->hasTo('orders@acme.example.test') && $mail->purchaseOrder->is($acmePo));
    expect($acmePo->fresh()->status)->toBe(SupplierOrderStatus::SENT);
});

// --- the purchase order page ----------------------------------------------------------------------------------

it('shows the order, ship-to, lines, supplier, totals and history on the purchase order page', function () {
    $order = storeAdminOrder($this);
    $po = purchaseOrderOf($order, $this->acme);
    app(App\Services\Marketplace\PurchaseOrderService::class)->recordSupplierCosts($this->admin, $po, 1100);
    $shipTo = $order->shipping_address;

    $this->actingAs($this->admin)->get(SupplierOrderResource::getUrl('view', ['record' => $po]))
        ->assertOk()
        ->assertSee($po->number)
        ->assertSee('Acme Wholesale')->assertSee('orders@acme.example.test')
        ->assertSee($order->number)
        ->assertSee($shipTo['name'])->assertSee($shipTo['line1'])
        ->assertSee('ACM-100')->assertSee('Dropped Midrange 5')->assertSee('$60.00')->assertSee('$120.00')
        ->assertSee('$11.00')            // supplier shipping we entered
        ->assertSee('$131.00')           // total cost: 2 x 60 + 11
        ->assertSee('History')->assertSee('Created (order paid)')
        ->assertDontSee($this->buyer->email); // the buyer's e-mail address never goes to a supplier, nor into their purchase order

    // a portal supplier says so
    $this->actingAs($this->admin)->get(SupplierOrderResource::getUrl('view', ['record' => purchaseOrderOf($order, $this->bolt)]))
        ->assertOk()->assertSee('Web portal: no order e-mail');
});

it('offers only the actions that fit the status', function () {
    $order = storeAdminOrder($this);
    $po = purchaseOrderOf($order, $this->acme);
    $portal = purchaseOrderOf($order, $this->bolt);

    $page = fn (SupplierOrder $record) => Livewire::actingAs($this->admin)->test(ViewSupplierOrder::class, ['record' => $record->getKey()]);

    // pending, with an e-mail address: send / mark placed / costs / csv / flag / cancel, but not confirm or ship yet
    $page($po)->assertActionVisible('send')->assertActionVisible('placedManually')->assertActionHidden('confirm')->assertActionHidden('markShipped')
        ->assertActionVisible('recordCosts')->assertActionVisible('downloadCsv')->assertActionVisible('flagProblem')->assertActionVisible('cancel');

    // pending, web portal: no mail to send
    $page($portal)->assertActionHidden('send')->assertActionVisible('placedManually');

    app(App\Services\Marketplace\PurchaseOrderService::class)->send($this->admin, $po);
    $page($po->fresh())->assertActionHasLabel('send', 'Send again')->assertActionHidden('placedManually')->assertActionVisible('confirm')->assertActionVisible('markShipped');
});

it('sends the purchase order by e-mail after a confirmation that names the recipient', function () {
    $po = purchaseOrderOf(storeAdminOrder($this), $this->acme);

    $page = Livewire::actingAs($this->admin)->test(ViewSupplierOrder::class, ['record' => $po->getKey()]);
    $page->mountAction('send')->assertSee('orders@acme.example.test')->assertSee('store@sdlabs.test');
    $page->callMountedAction()->assertNotified('Purchase order sent');

    Mail::assertQueued(PurchaseOrderMail::class, fn (PurchaseOrderMail $mail) => $mail->hasTo('orders@acme.example.test') && $mail->purchaseOrder->is($po));
    expect($po->fresh())->status->toBe(SupplierOrderStatus::SENT)->sent_at->not->toBeNull();
});

it('marks a portal order as placed with the supplier\'s reference, and insists on a reference', function () {
    $po = purchaseOrderOf(storeAdminOrder($this), $this->bolt);
    $page = Livewire::actingAs($this->admin)->test(ViewSupplierOrder::class, ['record' => $po->getKey()]);

    $page->callAction('placedManually', data: ['reference' => ''])->assertHasActionErrors(['reference' => 'required']);
    expect($po->fresh()->status)->toBe(SupplierOrderStatus::PENDING);

    $page->callAction('placedManually', data: ['reference' => 'PORTAL-48213'])->assertHasNoActionErrors()->assertNotified('Marked as placed');

    expect($po->fresh())->status->toBe(SupplierOrderStatus::SENT)->supplier_reference->toBe('PORTAL-48213')->placed_manually->toBeTrue();
    Mail::assertNothingQueued();
});

it('confirms a sent purchase order, with or without the supplier\'s order number', function () {
    $po = purchaseOrderOf(storeAdminOrder($this), $this->acme);
    app(App\Services\Marketplace\PurchaseOrderService::class)->send($this->admin, $po);

    Livewire::actingAs($this->admin)->test(ViewSupplierOrder::class, ['record' => $po->getKey()])
        ->callAction('confirm', data: ['reference' => 'SO-7741'])->assertHasNoActionErrors()->assertNotified('Purchase order confirmed');

    expect($po->fresh())->status->toBe(SupplierOrderStatus::CONFIRMED)->supplier_reference->toBe('SO-7741')->confirmed_at->not->toBeNull();
});

it('records what the supplier charges: line costs, shipping and the dropship fee', function () {
    $po = purchaseOrderOf(storeAdminOrder($this), $this->acme);
    $line = $po->items->first();
    expect($po->shipping_cost_cents)->toBeNull();

    Livewire::actingAs($this->admin)->test(ViewSupplierOrder::class, ['record' => $po->getKey()])
        ->mountAction('recordCosts')
        ->assertActionDataSet(['shipping_cost' => null, 'dropship_fee' => '0.00', 'unit_costs' => [$line->getKey() => '60.00']])
        ->assertSee('Dropped Midrange 5');

    Livewire::actingAs($this->admin)->test(ViewSupplierOrder::class, ['record' => $po->getKey()])
        ->callAction('recordCosts', data: ['unit_costs' => [$line->getKey() => '58.50'], 'shipping_cost' => '12.25', 'dropship_fee' => '3.00'])
        ->assertHasNoActionErrors()
        ->assertNotified('Costs saved');

    $po->refresh();
    expect($po->shipping_cost_cents)->toBe(1225)
        ->and($po->dropship_fee_cents)->toBe(300)
        ->and($po->items->first()->unit_cost_cents)->toBe(5850)
        ->and($po->items_cost_cents)->toBe(2 * 5850)
        ->and($po->totalCostCents())->toBe(2 * 5850 + 1225 + 300)
        // the real cost also replaces the snapshot on the order line, which the margin report uses
        ->and($po->items->first()->orderItem->fresh()->unit_cost_cents)->toBe(5850);
});

it('marks a purchase order shipped with the supplier\'s tracking and tells the buyer without naming the supplier', function () {
    $order = storeAdminOrder($this);
    $po = purchaseOrderOf($order, $this->acme);
    app(App\Services\Marketplace\PurchaseOrderService::class)->send($this->admin, $po);
    Notification::fake();

    $page = Livewire::actingAs($this->admin)->test(ViewSupplierOrder::class, ['record' => $po->getKey()]);
    $page->callAction('markShipped', data: ['carrier' => '', 'tracking_number' => ''])->assertHasActionErrors(['carrier' => 'required', 'tracking_number' => 'required']);

    $page->callAction('markShipped', data: ['carrier' => 'UPS', 'tracking_number' => '1ZSUPPLIER1', 'tracking_url' => 'https://ups.example/1ZSUPPLIER1'])
        ->assertHasNoActionErrors()
        ->assertNotified('Marked as shipped');

    expect($po->fresh())->status->toBe(SupplierOrderStatus::SHIPPED)
        ->and($po->shipments()->first())->tracking_number->toBe('1ZSUPPLIER1')->carrier->toBe('UPS');
    Notification::assertSentTo($this->buyer, OrderShipped::class);
    expect($order->sellerOrders->first()->fresh()->status)->toBe(SellerOrderStatus::PARTIALLY_SHIPPED);
});

it('says why an action was refused instead of failing', function () {
    $order = storeAdminOrder($this);
    $po = purchaseOrderOf($order, $this->acme);
    app(App\Services\Marketplace\PurchaseOrderService::class)->send($this->admin, $po);

    // the buyer was refunded after the order went out: the purchase order is flagged and there is nothing to ship any more
    app(App\Services\Marketplace\RefundService::class)->refundSellerOrder($this->admin, $order->sellerOrders->first(), 'Changed mind');
    expect($po->fresh()->status)->toBe(SupplierOrderStatus::PROBLEM);

    Livewire::actingAs($this->admin)->test(ViewSupplierOrder::class, ['record' => $po->getKey()])
        ->assertSee('Cancel with supplier')
        ->callAction('markShipped', data: ['carrier' => 'UPS', 'tracking_number' => '1Z'])
        ->assertNotified('Not done');

    expect($po->fresh()->status)->toBe(SupplierOrderStatus::PROBLEM)->and($po->shipments()->count())->toBe(0);
});

it('cancels a purchase order and flags a problem with a note', function () {
    $order = storeAdminOrder($this);
    $po = purchaseOrderOf($order, $this->acme);
    $other = purchaseOrderOf($order, $this->bolt);

    Livewire::actingAs($this->admin)->test(ViewSupplierOrder::class, ['record' => $other->getKey()])
        ->callAction('flagProblem', data: ['note' => ''])->assertHasActionErrors(['note' => 'required'])
        ->callAction('flagProblem', data: ['note' => 'Back-ordered until March'])->assertNotified('Flagged as a problem');

    expect($other->fresh())->status->toBe(SupplierOrderStatus::PROBLEM)->notes->toContain('Back-ordered until March');

    Livewire::actingAs($this->admin)->test(ViewSupplierOrder::class, ['record' => $po->getKey()])
        ->callAction('cancel', data: ['note' => 'Supplier discontinued it'])->assertNotified('Purchase order cancelled');

    expect($po->fresh())->status->toBe(SupplierOrderStatus::CANCELLED)->cancelled_at->not->toBeNull()->notes->toContain('Supplier discontinued it');

    // nothing more to do with a cancelled one except the CSV
    Livewire::actingAs($this->admin)->test(ViewSupplierOrder::class, ['record' => $po->getKey()])
        ->assertActionHidden('cancel')->assertActionHidden('flagProblem')->assertActionHidden('send')->assertActionHidden('markShipped');
});

it('downloads the purchase order as a CSV', function () {
    $po = purchaseOrderOf(storeAdminOrder($this), $this->acme);

    Livewire::actingAs($this->admin)->test(ViewSupplierOrder::class, ['record' => $po->getKey()])
        ->callAction('downloadCsv')
        ->assertFileDownloaded($po->number.'.csv', app(App\Services\Marketplace\PurchaseOrderService::class)->toCsv($po));

    expect(app(App\Services\Marketplace\PurchaseOrderService::class)->toCsv($po))->toContain('ACM-100')->toContain($po->number);
});

it('keeps non-admins out of every purchase order action', function () {
    $po = purchaseOrderOf(storeAdminOrder($this), $this->acme);

    // the page itself is closed to them ...
    Livewire::actingAs($this->buyer)->test(ViewSupplierOrder::class, ['record' => $po->getKey()])->assertForbidden();

    // ... and the service refuses a non-admin even when called directly
    expect(fn () => app(App\Services\Marketplace\PurchaseOrderService::class)->send($this->buyer, $po))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

// --- on the store order ---------------------------------------------------------------------------------------

it('lists the purchase orders on the store order, with where each line comes from', function () {
    $order = storeAdminOrder($this);
    $po = purchaseOrderOf($order, $this->acme);

    $manager = Livewire::actingAs($this->admin)->test(PurchaseOrdersRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
        ->assertCanSeeTableRecords($order->supplierOrders)
        ->assertSee($po->number)->assertSee('Acme Wholesale')->assertSee('To send');

    $manager->callTableAction('send', $po)->assertNotified('Purchase order sent');
    expect($po->fresh()->status)->toBe(SupplierOrderStatus::SENT);

    $this->actingAs($this->admin)->get(OrderResource::getUrl('view', ['record' => $order]))
        ->assertOk()->assertSee('Purchase orders')->assertSee('Fulfilled by')->assertSee('SDLabs stock')->assertSee('Supplier ('.$po->number.')');
});

it('shows no purchase orders on an order without supplier lines', function () {
    $listing = storeListing(['quantity' => 3], null, $this->admin);
    $order = paidOrderOf($this->buyer, [[$listing, 1]]);

    Livewire::actingAs($this->admin)->test(PurchaseOrdersRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
        ->assertCountTableRecords(0)->assertSee('No purchase orders');
});
