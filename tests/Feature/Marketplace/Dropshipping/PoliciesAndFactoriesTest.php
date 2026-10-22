<?php

use App\Enums\FulfillmentMode;
use App\Enums\FulfillmentSource;
use App\Enums\SellerOrderStatus;
use App\Enums\SupplierOrderStatus;
use App\Models\Listing;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
| R3: only admins manage suppliers and purchase orders; the factories build consistent dropshipping data.
*/

it('lets only admins touch suppliers and purchase orders', function () {
    $admin = adminUser();
    $member = onboardedSeller();
    $buyer = User::factory()->create();
    $supplier = Supplier::factory()->create();
    $po = SupplierOrder::factory()->create();

    foreach (['viewAny' => [Supplier::class], 'create' => [Supplier::class], 'view' => [$supplier], 'update' => [$supplier], 'delete' => [$supplier], 'deleteAny' => [Supplier::class]] as $ability => $arguments) {
        expect(Gate::forUser($admin)->allows($ability, $arguments))->toBeTrue("admin {$ability} supplier")
            ->and(Gate::forUser($member)->allows($ability, $arguments))->toBeFalse("member {$ability} supplier")
            ->and(Gate::forUser($buyer)->allows($ability, $arguments))->toBeFalse("buyer {$ability} supplier");
    }

    foreach (['viewAny' => [SupplierOrder::class], 'create' => [SupplierOrder::class], 'view' => [$po], 'update' => [$po], 'delete' => [$po], 'deleteAny' => [SupplierOrder::class]] as $ability => $arguments) {
        expect(Gate::forUser($admin)->allows($ability, $arguments))->toBeTrue("admin {$ability} PO")
            ->and(Gate::forUser($member)->allows($ability, $arguments))->toBeFalse("member {$ability} PO")
            ->and(Gate::forUser($buyer)->allows($ability, $arguments))->toBeFalse("buyer {$ability} PO");
    }

    // not even the buyer of the order or the owner of the order's other parts
    expect(Gate::forUser($po->order->user)->allows('view', $po))->toBeFalse();
});

it('builds suppliers with and without a feed', function () {
    $plain = Supplier::factory()->create();
    $feed = Supplier::factory()->withFeed('https://feeds.example.test/a.csv')->create();
    $file = Supplier::factory()->withFeedFile('suppliers/x.csv')->create();
    $manual = Supplier::factory()->manual()->autoSend()->create();

    expect($plain->hasFeed())->toBeFalse()
        ->and($feed->hasFeed())->toBeTrue()->and($feed->feed_url)->toBe('https://feeds.example.test/a.csv')->and($feed->feed_cost_column)->toBe('cost')
        ->and($file->hasFeed())->toBeTrue()->and($file->feed_file_path)->toBe('suppliers/x.csv')
        ->and($manual->order_email)->toBeNull()->and($manual->auto_send_purchase_orders)->toBeTrue()
        ->and($plain->active)->toBeTrue()->and($plain->blind_dropship)->toBeTrue()
        ->and(Supplier::factory()->inactive()->create()->active)->toBeFalse()
        ->and(Supplier::query()->withFeed()->whereKey([$plain->id, $feed->id, $file->id, $manual->id])->pluck('id')->all())->toBe([$feed->id, $file->id]);
});

it('formats a supplier\'s lead time and prices its parcels', function () {
    expect(Supplier::factory()->make(['lead_time_min_days' => 5, 'lead_time_max_days' => 8])->leadTimeText())->toBe('5–8 business days')
        ->and(Supplier::factory()->make(['lead_time_min_days' => 3, 'lead_time_max_days' => 3])->leadTimeText())->toBe('3 business days')
        ->and(Supplier::factory()->make(['lead_time_min_days' => 1, 'lead_time_max_days' => 1])->leadTimeText())->toBe('1 business day')
        ->and(Supplier::factory()->make(['lead_time_min_days' => 0, 'lead_time_max_days' => 0])->leadTimeText())->toBe('');

    $supplier = Supplier::factory()->shipping(1000, null, 250)->make(['ships_from_country' => 'US', 'free_shipping_over_cents' => null]);
    expect($supplier->shippingCentsFor('US', 1))->toBe(1000)
        ->and($supplier->shippingCentsFor('us', 4))->toBe(1750)
        ->and($supplier->shippingCentsFor('DE', 1))->toBeNull();
});

it('builds dropship and hybrid store listings', function () {
    $supplier = Supplier::factory()->create();
    $dropship = Listing::factory()->dropship($supplier)->create();
    $hybrid = Listing::factory()->hybrid($supplier, 4)->supplierStock(7)->create();

    expect($dropship->fulfillment)->toBe(FulfillmentMode::DROPSHIP)
        ->and($dropship->isStore())->toBeTrue()
        ->and($dropship->seller->isPlatformSeller())->toBeTrue()
        ->and($dropship->supplier_id)->toBe($supplier->id)
        ->and($dropship->supplier_sku)->not->toBeNull()
        ->and($dropship->cost_cents)->toBeGreaterThan(0)->toBeLessThan($dropship->price_cents)
        ->and($dropship->quantity)->toBe(0)
        ->and($dropship->channelRuleViolations())->toBe([])
        ->and($hybrid->fulfillment)->toBe(FulfillmentMode::STOCK_THEN_DROPSHIP)
        ->and($hybrid->quantity)->toBe(4)
        ->and($hybrid->supplier_stock)->toBe(7)
        ->and($hybrid->supplier_stock_synced_at)->not->toBeNull()
        ->and(Listing::factory()->create()->fulfillment)->toBe(FulfillmentMode::STOCK);
});

it('builds purchase orders in every state, each with a consistent order behind it', function () {
    foreach (['pending', 'sent', 'confirmed', 'shipped', 'cancelled', 'problem', 'placedManually'] as $state) {
        $po = SupplierOrder::factory()->{$state}()->create();
        $po->load(['items', 'order', 'sellerOrder.items', 'supplier']);

        expect($po->number)->toMatch('/^PO-[A-Z2-9]{6}$/')
            ->and($po->items)->toHaveCount(1)
            ->and($po->items_cost_cents)->toBe($state === 'cancelled' ? $po->items_cost_cents : 12000)
            ->and($po->order->isPaid())->toBeTrue()
            ->and($po->sellerOrder->seller->isPlatformSeller())->toBeTrue()
            ->and($po->sellerOrder->items->sole()->fulfillment_source)->toBe(FulfillmentSource::SUPPLIER)
            ->and($po->sellerOrder->items->sole()->supplier_id)->toBe($po->supplier_id)
            ->and($po->shipping_address)->not->toBeNull();
    }

    expect(SupplierOrder::factory()->pending()->create()->status)->toBe(SupplierOrderStatus::PENDING)
        ->and(SupplierOrder::factory()->sent()->create()->sent_at)->not->toBeNull()
        ->and(SupplierOrder::factory()->placedManually('PORTAL-9')->create())->placed_manually->toBeTrue()
        ->and(SupplierOrder::factory()->confirmed('SO-1')->create())->supplier_reference->toBe('SO-1')
        ->and(SupplierOrder::factory()->withCosts(1200, 300)->create())->shipping_cost_cents->toBe(1200)
        ->and(SupplierOrder::factory()->cancelled()->create()->items()->whereNull('cancelled_at')->count())->toBe(0)
        ->and(SupplierOrder::factory()->problem('Cancel with supplier: x')->create())->notes->toBe('Cancel with supplier: x');

    $shipped = SupplierOrder::factory()->shipped()->create();
    expect($shipped->status)->toBe(SupplierOrderStatus::SHIPPED)
        ->and($shipped->shipments)->toHaveCount(1)
        ->and($shipped->shipments->first()->items->sum('quantity'))->toBe(2)
        ->and($shipped->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::SHIPPED)
        ->and($shipped->sellerOrder->fresh()->shipped_at->equalTo($shipped->shipped_at))->toBeTrue();
});

it('builds shipments, and every shipped fixture seller order has one', function () {
    $shipment = Shipment::factory()->create();
    expect($shipment->hasTracking())->toBeTrue()->and($shipment->items)->toHaveCount(0)->and($shipment->sellerOrder)->toBeInstanceOf(SellerOrder::class);

    $shippedFixture = SellerOrder::factory()->shipped()->create();
    expect($shippedFixture->shipments)->toHaveCount(1)
        ->and($shippedFixture->shipments->first()->tracking_number)->toBe($shippedFixture->tracking_number);

    $withUrl = Shipment::factory()->withTrackingUrl()->create();
    expect($withUrl->tracking_url)->toContain($withUrl->tracking_number);
});

it('builds store orders with dropshipped lines: purchase orders, and one parcel per source once shipped', function () {
    $supplier = Supplier::factory()->create();
    $buyer = User::factory()->create();
    $stock = Listing::factory()->inStore()->create(['price_cents' => 8000, 'quantity' => 5, 'cost_cents' => 5000]);
    $dropped = Listing::factory()->dropship($supplier)->create(['price_cents' => 10000, 'cost_cents' => 6000]);

    // seller orders are per seller: use one admin for both
    $dropped->update(['seller_id' => $stock->seller_id]);

    $paid = Order::factory()->for($buyer, 'user')->withShippingAddress()->paid([[$stock, 1], [$dropped, 2]])->create();
    $po = $paid->supplierOrders()->sole();
    $items = $paid->items->keyBy('purchasable_id');

    expect($po->status)->toBe(SupplierOrderStatus::PENDING)
        ->and($po->items_cost_cents)->toBe(12000)
        ->and($items[$dropped->id]->fulfillment_source)->toBe(FulfillmentSource::SUPPLIER)
        ->and($items[$dropped->id]->unit_cost_cents)->toBe(6000)
        ->and($items[$stock->id]->fulfillment_source)->toBe(FulfillmentSource::STOCK)
        // 900 shipping for the own-stock unit + the supplier's parcel of two (899 + 300)
        ->and($paid->sellerOrders->sole()->shipping_cents)->toBe($stock->shippingCentsFor('US', 1) + $supplier->shippingCentsFor('US', 2, 20000));

    $shipped = Order::factory()->for($buyer, 'user')->withShippingAddress()->shipped([[$stock, 1], [$dropped, 2]])->create();
    $sellerOrder = $shipped->sellerOrders->sole();

    expect($sellerOrder->status)->toBe(SellerOrderStatus::SHIPPED)
        ->and($sellerOrder->shipments)->toHaveCount(2)
        ->and($sellerOrder->shipped_at->equalTo($sellerOrder->shipments->max('shipped_at')))->toBeTrue()
        ->and($shipped->supplierOrders()->sole()->status)->toBe(SupplierOrderStatus::SHIPPED)
        ->and($sellerOrder->shipments->whereNotNull('supplier_order_id'))->toHaveCount(1);
});
