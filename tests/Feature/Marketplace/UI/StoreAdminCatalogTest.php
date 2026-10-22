<?php

use App\Enums\FulfillmentMode;
use App\Enums\ListingStatus;
use App\Enums\SellerOrderStatus;
use App\Filament\Resources\ListingResource;
use App\Filament\Resources\ListingResource\Pages\CreateListing;
use App\Filament\Resources\ListingResource\Pages\EditListing;
use App\Filament\Resources\ListingResource\Pages\ListListings;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\Resources\OrderResource\RelationManagers\SellerOrdersRelationManager;
use App\Filament\Resources\SupplierResource;
use App\Filament\Resources\SupplierResource\Pages\CreateSupplier;
use App\Filament\Resources\SupplierResource\Pages\EditSupplier;
use App\Filament\Resources\SupplierResource\Pages\ListSuppliers;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\OrderShipped;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| R3 part 2, admin: suppliers (form, table, stock sync, feed template), the dropshipping fields of a store listing with
| its live margin, and "Ship SDLabs parcel" on a store order.
*/

beforeEach(function () {
    Storage::fake('private');
    Storage::fake('public');
    config(['marketplace.fee_bps' => 1000, 'marketplace.min_margin_bps' => 1500, 'marketplace.private_disk' => 'private']);
    $this->admin = adminUser();
    $this->buyer = User::factory()->create();
});

// --- suppliers: access -----------------------------------------------------------------------------------------

it('opens the supplier pages for admins only, in the Store group', function () {
    $supplier = supplier();

    foreach (['/admin/suppliers', '/admin/suppliers/create', '/admin/suppliers/'.$supplier->getKey().'/edit'] as $url) {
        $this->actingAs($this->admin)->get($url)->assertOk();
        $this->actingAs($this->buyer)->get($url)->assertForbidden();
        $this->actingAs(onboardedSeller())->get($url)->assertForbidden();
    }

    expect(SupplierResource::getNavigationGroup())->toBe('Store');
    $this->actingAs($this->admin)->get('/admin/suppliers')->assertSee('Store')->assertSee('Suppliers');
});

// --- suppliers: form ---------------------------------------------------------------------------------------------

it('creates a supplier with every field, shipping money typed in dollars and an uploaded feed on the private disk', function () {
    $file = UploadedFile::fake()->createWithContent('stock.csv', "sku,quantity,cost\nA-1,5,12.00\n");

    Livewire::actingAs($this->admin)->test(CreateSupplier::class)
        ->fillForm([
            'name' => 'Northwind Test Wholesale',
            'active' => true,
            'website' => 'https://northwind.example.test',
            'account_number' => 'SDL-9',
            'ships_from_country' => 'CA',
            'notes' => 'Call Dana for rush orders.',
            'contact_name' => 'Dana Whitfield',
            'contact_email' => 'dana@northwind.example.test',
            'contact_phone' => '555-0142',
            'order_email' => 'orders@northwind.example.test',
            'auto_send_purchase_orders' => true,
            'blind_dropship' => false,
            'dropship_fee_cents' => '3.50',
            'lead_time_min_days' => 4,
            'lead_time_max_days' => 6,
            'shipping_domestic_cents' => '9.99',
            'shipping_international_cents' => '34.99',
            'additional_item_shipping_cents' => '3.99',
            'free_shipping_over_cents' => '250',
            'feed_file_path' => $file,
            'feed_sku_column' => 'Item No',
            'feed_quantity_column' => 'On hand',
            'feed_cost_column' => 'Dealer price',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $supplier = Supplier::where('name', 'Northwind Test Wholesale')->firstOrFail();

    expect($supplier)
        ->ships_from_country->toBe('CA')
        ->account_number->toBe('SDL-9')
        ->contact_name->toBe('Dana Whitfield')
        ->order_email->toBe('orders@northwind.example.test')
        ->auto_send_purchase_orders->toBeTrue()
        ->blind_dropship->toBeFalse()
        ->dropship_fee_cents->toBe(350)
        ->lead_time_min_days->toBe(4)
        ->lead_time_max_days->toBe(6)
        ->shipping_domestic_cents->toBe(999)
        ->shipping_international_cents->toBe(3499)
        ->additional_item_shipping_cents->toBe(399)
        ->free_shipping_over_cents->toBe(25000)
        ->feed_sku_column->toBe('Item No')
        ->feed_quantity_column->toBe('On hand')
        ->feed_cost_column->toBe('Dealer price')
        ->notes->toBe('Call Dana for rush orders.')
        ->and($supplier->feed_file_path)->not->toBeNull()
        ->and(Storage::disk('private')->exists($supplier->feed_file_path))->toBeTrue()
        ->and($supplier->hasFeed())->toBeTrue();
});

it('lets an international price and a free-shipping limit stay empty, and keeps defaults for the rest', function () {
    Livewire::actingAs($this->admin)->test(CreateSupplier::class)
        ->fillForm(['name' => 'Domestic Only Ltd', 'shipping_domestic_cents' => '7', 'shipping_international_cents' => '', 'free_shipping_over_cents' => '', 'additional_item_shipping_cents' => '2'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Supplier::where('name', 'Domestic Only Ltd')->firstOrFail())
        ->shipping_international_cents->toBeNull()
        ->free_shipping_over_cents->toBeNull()
        ->shipping_domestic_cents->toBe(700)
        ->active->toBeTrue()
        ->blind_dropship->toBeTrue()
        ->lead_time_min_days->toBe(3)
        ->lead_time_max_days->toBe(7)
        ->feed_sku_column->toBe('sku');
});

it('validates the supplier form: a name, an order e-mail for automatic sending, and a sensible delivery time', function () {
    $create = fn (array $data) => Livewire::actingAs($this->admin)->test(CreateSupplier::class)->fillForm($data)->call('create');

    $create(['name' => ''])->assertHasFormErrors(['name' => 'required']);
    $create(['name' => 'Auto', 'auto_send_purchase_orders' => true, 'order_email' => ''])->assertHasFormErrors(['order_email' => 'required']);
    $create(['name' => 'Bad mail', 'order_email' => 'not-an-email'])->assertHasFormErrors(['order_email' => 'email']);
    $create(['name' => 'Bad time', 'lead_time_min_days' => 8, 'lead_time_max_days' => 3])->assertHasFormErrors(['lead_time_max_days']);
    $create(['name' => 'Bad url', 'feed_url' => 'not a url'])->assertHasFormErrors(['feed_url']);
    $create(['name' => 'Negative', 'shipping_domestic_cents' => '-1'])->assertHasFormErrors(['shipping_domestic_cents']);

    expect(Supplier::whereIn('name', ['Auto', 'Bad mail', 'Bad time', 'Bad url', 'Negative'])->count())->toBe(0);
});

it('edits a supplier and shows what the form loaded in dollars', function () {
    $supplier = supplier(['name' => 'Editable Co', 'shipping_domestic_cents' => 1299, 'dropship_fee_cents' => 250, 'shipping_international_cents' => null]);

    Livewire::actingAs($this->admin)->test(EditSupplier::class, ['record' => $supplier->getKey()])
        ->assertFormSet(['name' => 'Editable Co', 'shipping_domestic_cents' => '12.99', 'dropship_fee_cents' => '2.50', 'shipping_international_cents' => null])
        ->fillForm(['name' => 'Renamed Co', 'active' => false, 'lead_time_max_days' => 9])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($supplier->fresh())->name->toBe('Renamed Co')->active->toBeFalse()->lead_time_max_days->toBe(9)->shipping_domestic_cents->toBe(1299);
});

// --- suppliers: table, sync and template -------------------------------------------------------------------------

it('lists the suppliers with the feed status, the last sync, the last error and the number of listings', function () {
    $ok = supplier(['name' => 'Syncs Fine']);
    $ok->forceFill(['feed_url' => 'https://feeds.example.test/ok.csv', 'feed_last_synced_at' => now()->subHour()])->save();
    $broken = supplier(['name' => 'Feed Broken', 'feed_url' => 'https://feeds.example.test/bad.csv', 'feed_last_error' => 'The feed returned HTTP 500.']);
    $fresh = supplier(['name' => 'Never Synced', 'feed_file_path' => 'suppliers/feeds/x.csv']);
    $none = supplier(['name' => 'No Feed At All']);
    dropshipListing($ok);
    dropshipListing($ok);

    expect(SupplierResource::feedStatus($ok))->toBe('Synced')
        ->and(SupplierResource::feedStatus($broken))->toBe('Error')
        ->and(SupplierResource::feedStatus($fresh))->toBe('Not synced yet')
        ->and(SupplierResource::feedStatus($none))->toBe('No feed');

    Livewire::actingAs($this->admin)->test(ListSuppliers::class)
        ->assertCanSeeTableRecords([$ok, $broken, $fresh, $none])
        ->assertTableColumnStateSet('feed_status', 'Synced', $ok)
        ->assertTableColumnStateSet('feed_status', 'Error', $broken)
        ->assertTableColumnStateSet('listings_count', 2, (string) $ok->getKey())
        ->assertTableColumnStateSet('feed_last_error', 'The feed returned HTTP 500.', $broken)
        ->assertSee('The feed returned HTTP 500.')
        ->filterTable('feed_errors', true)->assertCanSeeTableRecords([$broken])->assertCanNotSeeTableRecords([$ok, $none])
        ->resetTableFilters()
        ->filterTable('active', false)->assertCanNotSeeTableRecords([$ok]);
});

it('syncs a supplier\'s stock from the list and the edit page and shows the result as a notification', function () {
    Storage::disk('private')->put('suppliers/feeds/acme.csv', "sku,quantity,cost\nACM-1,14,61.00\nACM-2,0,\n");
    $supplier = supplier(['feed_file_path' => 'suppliers/feeds/acme.csv', 'feed_cost_column' => 'cost']);
    $one = dropshipListing($supplier, ['supplier_sku' => 'ACM-1', 'cost_cents' => 6000]);
    $two = dropshipListing($supplier, ['supplier_sku' => 'ACM-2']);

    Livewire::actingAs($this->admin)->test(ListSuppliers::class)
        ->assertTableActionVisible('syncNow', $supplier)
        ->callTableAction('syncNow', $supplier)
        ->assertNotified('Stock synced');

    expect($one->fresh())->supplier_stock->toBe(14)->cost_cents->toBe(6100)
        ->and($two->fresh()->supplier_stock)->toBe(0)
        ->and($supplier->fresh())->feed_last_synced_at->not->toBeNull()->feed_last_error->toBeNull();

    // the result text names what happened
    Livewire::actingAs($this->admin)->test(EditSupplier::class, ['record' => $supplier->getKey()])
        ->callAction('syncNow')
        ->assertNotified('Stock synced');

    // no feed, no button
    Livewire::actingAs($this->admin)->test(ListSuppliers::class)->assertTableActionHidden('syncNow', supplier(['name' => 'No feed here']));
});

it('reports a stock feed that cannot be read and records the error on the supplier', function () {
    Http::fake(['feeds.example.test/*' => Http::response('nope', 500)]);
    $supplier = supplier(['feed_url' => 'https://feeds.example.test/stock.csv']);
    $listing = dropshipListing($supplier, ['supplier_sku' => 'X-1', 'supplier_stock' => 7]);

    Livewire::actingAs($this->admin)->test(ListSuppliers::class)
        ->callTableAction('syncNow', $supplier)
        ->assertNotified('The stock feed could not be read');

    expect($supplier->fresh()->feed_last_error)->toContain('HTTP 500')
        ->and($listing->fresh()->supplier_stock)->toBe(7); // a failed feed changes no stock
});

it('downloads a CSV template with the configured column headers and the supplier\'s SKUs', function () {
    $supplier = supplier(['name' => 'Template Co', 'feed_sku_column' => 'Item No', 'feed_quantity_column' => 'On hand', 'feed_cost_column' => 'Dealer price']);
    dropshipListing($supplier, ['supplier_sku' => 'TPL-1']);
    dropshipListing($supplier, ['supplier_sku' => 'TPL-2']);

    $csv = "\"Item No\",\"On hand\",\"Dealer price\"\nTPL-1,,\nTPL-2,,\n";
    expect(app(App\Services\Marketplace\SupplierFeedSync::class)->templateCsv($supplier))->toBe($csv);

    Livewire::actingAs($this->admin)->test(ListSuppliers::class)
        ->callTableAction('feedTemplate', $supplier)
        ->assertFileDownloaded('template-co-stock-feed-template.csv', $csv);

    Livewire::actingAs($this->admin)->test(EditSupplier::class, ['record' => $supplier->getKey()])
        ->callAction('feedTemplate')
        ->assertFileDownloaded('template-co-stock-feed-template.csv', $csv);

    // a supplier with no SKUs yet gets one example row, and no cost column unless mapped
    $empty = supplier(['feed_cost_column' => null]);
    expect(app(App\Services\Marketplace\SupplierFeedSync::class)->templateCsv($empty))->toBe("sku,quantity\nEXAMPLE-SKU-001,10\n");

    // the template survives a round trip: the SKUs are read, the empty quantities are skipped
    $parsed = app(App\Services\Marketplace\SupplierFeedSync::class)->parse("sku,quantity\nEXAMPLE-SKU-001,10\n");
    expect($parsed['rows'])->toHaveKey('example-sku-001');
});

it('removes a supplier that nothing uses, and keeps one with listings or purchase orders', function () {
    $unused = supplier(['name' => 'Unused Co']);
    $used = supplier(['name' => 'Used Co']);
    dropshipListing($used);

    expect(SupplierResource::canBeDeleted($unused))->toBeTrue()->and(SupplierResource::canBeDeleted($used))->toBeFalse();

    Livewire::actingAs($this->admin)->test(ListSuppliers::class)
        ->assertTableActionHidden('delete', $used)
        ->callTableAction('delete', $unused);

    expect(Supplier::find($unused->getKey()))->toBeNull()->and(Supplier::find($used->getKey()))->not->toBeNull();
});

// --- the store listing form ------------------------------------------------------------------------------------

/** A complete store listing form; overrides win. */
function storeListingForm(object $test, Driver $driver, array $overrides = []): array
{
    return $overrides + [
        'seller_id' => $test->admin->id, 'title' => 'Dropship form probe', 'status' => 'draft', 'channel' => 'store', 'category' => 'driver',
        'driver_id' => $driver->id, 'condition' => 'new', 'price_cents' => '100.00', 'quantity' => 4, 'units_per_lot' => 1,
        'ships_from_country' => 'US', 'handling_days' => 2, 'shipping_domestic_cents' => '8.99', 'additional_item_shipping_cents' => '2',
        'fulfillment' => 'stock',
    ];
}

it('saves the dropshipping fields of a store listing: mode, supplier, supplier SKU and cost', function () {
    $supplier = supplier(['name' => 'Form Supplier']);
    $driver = Driver::factory()->create();

    Livewire::actingAs($this->admin)->test(CreateListing::class)
        ->fillForm(storeListingForm($this, $driver, ['fulfillment' => 'dropship', 'supplier_id' => $supplier->getKey(), 'supplier_sku' => 'FS-100', 'cost_cents' => '61.50']))
        ->call('create')
        ->assertHasNoFormErrors();

    $listing = Listing::where('title', 'Dropship form probe')->firstOrFail();

    expect($listing)
        ->fulfillment->toBe(FulfillmentMode::DROPSHIP)
        ->supplier_id->toBe($supplier->getKey())
        ->supplier_sku->toBe('FS-100')
        ->cost_cents->toBe(6150)
        ->price_cents->toBe(10000)
        ->quantity->toBe(0); // a product the supplier always ships has no stock of its own
});

it('requires a supplier for dropship and hybrid listings, and the model rules agree', function () {
    $driver = Driver::factory()->create();
    $create = fn (array $overrides) => Livewire::actingAs($this->admin)->test(CreateListing::class)->fillForm(storeListingForm($this, $driver, $overrides))->call('create');

    $create(['fulfillment' => 'dropship', 'supplier_id' => null])->assertHasFormErrors(['supplier_id' => 'required']);
    $create(['fulfillment' => 'stock_then_dropship', 'supplier_id' => null, 'quantity' => 3])->assertHasFormErrors(['supplier_id' => 'required']);
    expect(Listing::where('title', 'Dropship form probe')->count())->toBe(0);

    // the same rule lives on the model (ListingResource uses it as a second line of defence)
    expect(ListingResource::channelViolations(['channel' => 'store', 'category' => 'driver', 'condition' => 'new', 'seller_id' => $this->admin->id, 'driver_id' => $driver->id, 'fulfillment' => 'dropship', 'supplier_id' => null]))
        ->toContain('Choose the supplier that ships this product.');
    expect(ListingResource::channelViolations(['channel' => 'marketplace', 'category' => 'kit', 'condition' => 'new', 'seller_id' => $this->admin->id, 'fulfillment' => 'dropship', 'supplier_id' => supplier()->getKey()]))
        ->toContain('Only store listings can be dropshipped.');
});

it('keeps own stock and a supplier for hybrid listings, and drops the supplier when switching back to own stock', function () {
    $supplier = supplier();
    $driver = Driver::factory()->create();

    Livewire::actingAs($this->admin)->test(CreateListing::class)
        ->fillForm(storeListingForm($this, $driver, ['fulfillment' => 'stock_then_dropship', 'quantity' => 3, 'supplier_id' => $supplier->getKey(), 'supplier_sku' => 'H-1', 'cost_cents' => '60']))
        ->call('create')
        ->assertHasNoFormErrors();

    $listing = Listing::where('title', 'Dropship form probe')->firstOrFail();
    expect($listing)->fulfillment->toBe(FulfillmentMode::STOCK_THEN_DROPSHIP)->quantity->toBe(3)->supplier_id->toBe($supplier->getKey());

    $listing->forceFill(['supplier_stock' => 9])->save();

    Livewire::actingAs($this->admin)->test(EditListing::class, ['record' => $listing->getRouteKey()])
        ->fillForm(['fulfillment' => 'stock'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($listing->fresh())->fulfillment->toBe(FulfillmentMode::STOCK)->supplier_id->toBeNull()->supplier_sku->toBeNull()->supplier_stock->toBeNull()
        ->quantity->toBe(3)->cost_cents->toBe(6000);
});

it('hides the own quantity and own shipping for a pure dropship listing, and shows them otherwise', function () {
    $listing = dropshipListing(null, ['supplier_stock' => 6]);

    $page = Livewire::actingAs($this->admin)->test(EditListing::class, ['record' => $listing->getRouteKey()]);
    $page->assertFormFieldIsHidden('quantity')->assertFormFieldIsHidden('shipping_domestic_cents')->assertFormFieldIsHidden('handling_days')
        ->assertFormFieldIsVisible('supplier_id')->assertFormFieldIsVisible('supplier_sku')->assertFormFieldIsVisible('cost_cents')
        ->assertSee('6 in stock at the supplier')->assertSee('come from the supplier');

    $page->fillForm(['fulfillment' => 'stock_then_dropship'])
        ->assertFormFieldIsVisible('quantity')->assertFormFieldIsVisible('shipping_domestic_cents')->assertFormFieldIsVisible('supplier_id');

    $page->fillForm(['fulfillment' => 'stock'])
        ->assertFormFieldIsVisible('quantity')->assertFormFieldIsHidden('supplier_id')->assertFormFieldIsHidden('supplier_sku')
        ->assertDontSee('in stock at the supplier')->assertDontSee('come from the supplier');
});

it('shows the fulfilment section for store listings only', function () {
    Livewire::actingAs($this->admin)->test(CreateListing::class)
        ->assertFormFieldIsVisible('fulfillment')->assertFormFieldIsVisible('cost_cents')->assertSee('Margin per unit')->assertSee('Fulfilment and cost')
        ->fillForm(['channel' => 'marketplace'])
        ->assertFormFieldIsHidden('fulfillment')->assertFormFieldIsHidden('cost_cents')->assertDontSee('Margin per unit')->assertDontSee('Fulfilment and cost');

    // a marketplace listing is never dropshipped, whatever the form carried
    $kit = sellableListing(attributes: ['title' => 'Plain member kit']);
    Livewire::actingAs($this->admin)->test(EditListing::class, ['record' => $kit->getRouteKey()])->fillForm(['title' => 'Plain member kit 2'])->call('save')->assertHasNoFormErrors();
    expect($kit->fresh())->fulfillment->toBe(FulfillmentMode::STOCK)->supplier_id->toBeNull();
});

it('shows the live unit margin next to the price and warns below the minimum margin', function () {
    $driver = Driver::factory()->create();
    $page = Livewire::actingAs($this->admin)->test(CreateListing::class)
        ->fillForm(storeListingForm($this, $driver, ['price_cents' => '100.00', 'cost_cents' => '60.00']));

    // 40 of 100: 40.0 %, fine
    $page->assertSeeHtml('data-unit-margin')->assertSee('$40.00 (40.0 %)')->assertDontSeeHtml('data-margin-warning');

    // 10 of 100: below 15 %
    $page->fillForm(['cost_cents' => '90.00'])->assertSee('$10.00 (10.0 %)')->assertSeeHtml('data-margin-warning')->assertSee('Low margin: 10.0 %');

    // a loss
    $page->fillForm(['cost_cents' => '120.00'])->assertSee('Sold at a loss');

    // no cost yet
    $page->fillForm(['cost_cents' => ''])->assertSee('Enter the price and the cost per unit')->assertDontSeeHtml('data-margin-warning');

    // the same numbers come from the model (and the list)
    expect(ListingResource::marginHtml(10000, 6000)->toHtml())->toContain('$40.00')->not->toContain('data-margin-warning')
        ->and(ListingResource::marginColor(10000, 6000))->toBe('success')
        ->and(ListingResource::marginColor(10000, 9000))->toBe('warning')
        ->and(ListingResource::marginColor(10000, 12000))->toBe('danger')
        ->and(ListingResource::marginColor(10000, null))->toBeNull();
});

it('shows fulfilment, stock and margin in the listings table and filters by mode, supplier and low margin', function () {
    $acme = supplier(['name' => 'Acme Table']);
    $bolt = supplier(['name' => 'Bolt Table']);
    $own = storeListing(['quantity' => 4, 'cost_cents' => 5000, 'price_cents' => 10000]);
    $drop = dropshipListing($acme, ['supplier_stock' => 7, 'cost_cents' => 6000, 'price_cents' => 10000]);
    $thin = hybridListing($bolt, 2, ['supplier_stock' => null, 'cost_cents' => 9000, 'price_cents' => 10000]);
    $market = sellableListing();

    expect(ListingResource::stockText($own))->toBe('4')
        ->and(ListingResource::stockText($drop))->toBe('Supplier: 7')
        ->and(ListingResource::stockText($thin))->toBe('2 + supplier ?')
        ->and(ListingResource::stockText($market))->toBe((string) $market->quantity);

    $table = Livewire::actingAs($this->admin)->test(ListListings::class)
        ->assertCanSeeTableRecords([$own, $drop, $thin, $market])
        ->assertTableColumnStateSet('margin', '50.0 %', $own)
        ->assertTableColumnStateSet('margin', '40.0 %', $drop)
        ->assertTableColumnStateSet('margin', '10.0 %', $thin)
        ->assertTableColumnStateSet('fulfillment', 'Dropship (supplier ships)', $drop);

    $table->filterTable('fulfillment', 'dropship')->assertCanSeeTableRecords([$drop])->assertCanNotSeeTableRecords([$own, $thin, $market])
        ->resetTableFilters()
        ->filterTable('supplier_id', $bolt->getKey())->assertCanSeeTableRecords([$thin])->assertCanNotSeeTableRecords([$drop, $own])
        ->resetTableFilters()
        ->filterTable('low_margin', true)->assertCanSeeTableRecords([$thin])->assertCanNotSeeTableRecords([$own, $drop, $market]);
});

it('keeps the cost and the supplier fields out of the buyer\'s reach', function () {
    $listing = dropshipListing(supplier(['name' => 'Very Secret Supplier']), ['supplier_sku' => 'SECRET-SKU-9', 'cost_cents' => 6123, 'supplier_stock' => 5]);

    $html = Livewire::test(App\Http\Livewire\BuyBox::class, ['purchasable' => $listing])->html();
    expect($html)->not->toContain('Very Secret Supplier')->not->toContain('SECRET-SKU-9')->not->toContain('61.23');
});

// --- Ship SDLabs parcel ------------------------------------------------------------------------------------------

/** Two own-stock units + one Acme unit, paid: a store order with a stock parcel and a supplier parcel to come. */
function shippableStoreOrder(object $test): array
{
    Mail::fake();
    Notification::fake();
    $test->acme = supplier(['name' => 'Acme Parcel Co', 'order_email' => 'orders@acme-parcel.example.test']);
    $stock = storeListing(['price_cents' => 8000, 'quantity' => 5, 'title' => 'Stock Woofer 9', 'shipping_domestic_cents' => 900, 'additional_item_shipping_cents' => 0], null, $test->admin);
    $dropped = dropshipListing($test->acme, ['price_cents' => 10000, 'cost_cents' => 6000, 'supplier_stock' => 9, 'title' => 'Dropped Tweeter 9'], $test->admin);

    $order = paidOrderOf($test->buyer, [[$stock, 2], [$dropped, 1]]);
    $sellerOrder = $order->sellerOrders->first();

    return [$order, $sellerOrder, $order->items->firstWhere('purchasable_id', $stock->id), $order->items->firstWhere('purchasable_id', $dropped->id)];
}

it('ships the own-stock items of a store order as one parcel from the order page and leaves the supplier item to its purchase order', function () {
    [$order, $sellerOrder, $stockItem, $supplierItem] = shippableStoreOrder($this);
    $po = purchaseOrderOf($order, $this->acme);

    $page = Livewire::actingAs($this->admin)->test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionVisible('shipParcel')
        ->assertActionHasLabel('shipParcel', 'Ship SDLabs parcel');

    // the form says which items ship from stock and which are the supplier's
    $page->mountAction('shipParcel')
        ->assertSee('Stock Woofer 9 (own stock, 2 to ship)')
        ->assertSee('Dropped Tweeter 9')->assertSee($po->number)
        ->assertActionDataSet(['quantities' => [$stockItem->getKey() => 2]])
        ->assertDontSee('Acme Parcel Co');

    $page->callAction('shipParcel', data: [
        'quantities' => [$stockItem->getKey() => 2],
        'carrier' => 'USPS', 'tracking_number' => '9400STOCK1', 'tracking_url' => 'https://usps.example/9400STOCK1',
    ])->assertHasNoActionErrors()->assertNotified('Parcel shipped');

    $sellerOrder->refresh();
    $shipment = $sellerOrder->shipments()->with('items')->firstOrFail();

    expect($sellerOrder->status)->toBe(SellerOrderStatus::PARTIALLY_SHIPPED)
        ->and($shipment)->carrier->toBe('USPS')->tracking_number->toBe('9400STOCK1')->supplier_order_id->toBeNull()
        ->and($shipment->items->pluck('quantity', 'order_item_id')->all())->toBe([$stockItem->getKey() => 2]);
    Notification::assertSentTo($this->buyer, OrderShipped::class);

    // nothing of our own is left: the action goes away, the supplier parcel is shipped from its purchase order
    Livewire::actingAs($this->admin)->test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('shipParcel');
});

it('ships part of the units when the admin enters fewer, and refuses an empty or too large parcel', function () {
    [$order, $sellerOrder, $stockItem] = shippableStoreOrder($this);
    $page = Livewire::actingAs($this->admin)->test(ViewOrder::class, ['record' => $order->getRouteKey()]);
    $tracking = ['carrier' => 'UPS', 'tracking_number' => '1ZPART'];

    $page->callAction('shipParcel', data: $tracking + ['quantities' => [$stockItem->getKey() => 3]])->assertHasActionErrors(['quantities.'.$stockItem->getKey()]);
    $page->callAction('shipParcel', data: $tracking + ['quantities' => [$stockItem->getKey() => 0]])->assertNotified('Nothing to ship');
    expect($sellerOrder->shipments()->count())->toBe(0);

    $page->callAction('shipParcel', data: $tracking + ['quantities' => [$stockItem->getKey() => 1]])->assertNotified('Parcel shipped');
    expect($sellerOrder->shipments()->first()->items->sum('quantity'))->toBe(1);

    // one unit is still ours to ship
    Livewire::actingAs($this->admin)->test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionVisible('shipParcel')
        ->mountAction('shipParcel')->assertSee('1 to ship');
});

it('requires a carrier and a tracking number for the parcel', function () {
    [$order, , $stockItem] = shippableStoreOrder($this);

    Livewire::actingAs($this->admin)->test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('shipParcel', data: ['quantities' => [$stockItem->getKey() => 1], 'carrier' => '', 'tracking_number' => ''])
        ->assertHasActionErrors(['carrier' => 'required', 'tracking_number' => 'required']);
});

it('ships the store parcel from the seller order list too, and no longer offers "Mark shipped" for a store order', function () {
    [$order, $sellerOrder, $stockItem] = shippableStoreOrder($this);

    $manager = Livewire::actingAs($this->admin)->test(SellerOrdersRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class]);

    $manager->assertTableActionVisible('shipParcel', $sellerOrder)->assertTableActionHidden('markShipped', $sellerOrder)
        ->assertTableColumnStateSet('parcels', '0 of 2 parcels shipped', $sellerOrder)
        ->callTableAction('shipParcel', $sellerOrder, data: ['quantities' => [$stockItem->getKey() => 2], 'carrier' => 'DHL', 'tracking_number' => 'JD0001'])
        ->assertNotified('Parcel shipped')
        ->assertTableColumnStateSet('parcels', '1 of 2 parcels shipped', $sellerOrder->fresh());

    expect($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::PARTIALLY_SHIPPED);

    // a regular member's order is shipped by the member, not by us
    [$marketOrderModel, $marketSellerOrder] = (function () {
        $x = paidListingOrder($this->buyer);

        return [$x['order'], $x['sellerOrder']];
    })->call($this);
    Livewire::actingAs($this->admin)->test(SellerOrdersRelationManager::class, ['ownerRecord' => $marketOrderModel, 'pageClass' => ViewOrder::class])
        ->assertTableActionHidden('shipParcel', $marketSellerOrder)->assertTableActionHidden('markShipped', $marketSellerOrder);
});

it('offers no store parcel when only supplier items are left, or when the order is not a store order', function () {
    $this->acme = supplier();
    $order = paidOrderOf($this->buyer, [[dropshipListing($this->acme, ['supplier_stock' => 4], $this->admin), 1]]);

    Livewire::actingAs($this->admin)->test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('shipParcel');

    $x = paidListingOrder($this->buyer);
    Livewire::actingAs($this->admin)->test(ViewOrder::class, ['record' => $x['order']->getRouteKey()])->assertActionHidden('shipParcel');
});

it('keeps a non-admin from shipping a store parcel', function () {
    [$order, $sellerOrder, $stockItem] = shippableStoreOrder($this);

    expect(fn () => app(App\Services\Marketplace\ShipmentService::class)->shipItems($this->buyer, $sellerOrder, [$stockItem->getKey() => 1], 'UPS', '1Z'))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    Livewire::actingAs($this->buyer)->test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertForbidden();
});
