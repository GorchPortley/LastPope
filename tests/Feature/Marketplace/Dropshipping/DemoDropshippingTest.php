<?php

use App\Enums\FulfillmentMode;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Enums\SupplierOrderStatus;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Services\Marketplace\MarginReport;
use App\Services\Marketplace\ParcelPlanner;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Storage;

/*
| The demo data (database/seeders/DemoSeeder.php) includes the R3 dropshipping set: two suppliers, dropshipped and hybrid
| store listings, store orders with purchase orders in several states and parcels from several sources.
*/

it('has a supplier with a CSV feed file on the private disk and one that is a web portal', function () {
    $suppliers = Supplier::all();
    $feed = $suppliers->firstWhere('name', 'Northwind Audio Wholesale');
    $portal = $suppliers->firstWhere('name', 'Harbor Pro Audio');

    expect($suppliers)->toHaveCount(2)
        ->and($feed->hasFeed())->toBeTrue()
        ->and(Storage::disk('private')->exists($feed->feed_file_path))->toBeTrue()
        ->and(Storage::disk('private')->get($feed->feed_file_path))->toContain('sku,quantity,cost')->toContain('NW-KX8-001')
        ->and($feed->feed_last_synced_at)->not->toBeNull()
        ->and($feed->feed_last_error)->toBeNull()
        ->and($feed->auto_send_purchase_orders)->toBeTrue()
        ->and($portal->hasFeed())->toBeFalse()
        ->and($portal->order_email)->toBeNull()
        ->and($portal->auto_send_purchase_orders)->toBeFalse();

    // the feed was read: stock and cost of its listings come from the file
    $kx8 = Listing::where('supplier_sku', 'NW-KX8-001')->firstOrFail();
    expect($kx8->supplier_stock)->toBe(14)->and($kx8->cost_cents)->toBe(10350)->and($kx8->supplier_stock_synced_at)->not->toBeNull();
});

it('has dropshipped and hybrid store listings among the plain ones, all within the rules and costed', function () {
    $store = Listing::store()->get();

    expect($store->where('fulfillment', FulfillmentMode::DROPSHIP))->toHaveCount(3)
        ->and($store->where('fulfillment', FulfillmentMode::STOCK_THEN_DROPSHIP))->toHaveCount(2)
        ->and($store->where('fulfillment', FulfillmentMode::STOCK)->count())->toBeGreaterThanOrEqual(5)
        ->and($store->every(fn ($l) => $l->cost_cents !== null && $l->cost_cents < $l->price_cents))->toBeTrue()
        ->and($store->every(fn ($l) => $l->channelRuleViolations() === []))->toBeTrue()
        ->and($store->where('fulfillment', '!=', FulfillmentMode::STOCK)->every(fn ($l) => $l->supplier_id !== null && $l->supplier_sku !== null && $l->isAvailableForPurchase()))->toBeTrue()
        // one of them sells at a thin margin on purpose, for the warning
        ->and($store->filter(fn ($l) => $l->lowMarginWarning() !== null))->toHaveCount(1);
});

it('has store orders with purchase orders in different states', function () {
    $states = SupplierOrder::pluck('status')->map->value->unique()->sort()->values()->all();

    expect($states)->toBe(['cancelled', 'confirmed', 'pending', 'problem', 'sent', 'shipped'])
        ->and(SupplierOrder::query()->status(SupplierOrderStatus::PENDING)->whereHas('supplier', fn ($q) => $q->where('auto_send_purchase_orders', false))->exists())->toBeTrue()
        ->and(SupplierOrder::query()->whereNotNull('supplier_reference')->count())->toBeGreaterThanOrEqual(3);

    foreach (SupplierOrder::with(['items', 'order', 'sellerOrder'])->get() as $po) {
        expect($po->items)->not->toBeEmpty()
            ->and($po->seller_order_id)->toBe($po->items->first()->orderItem->seller_order_id)
            ->and($po->sellerOrder->seller->isPlatformSeller())->toBeTrue()
            ->and($po->order->paid_at)->not->toBeNull()
            ->and($po->sent_at === null || $po->sent_at->gte($po->order->paid_at))->toBeTrue($po->number)
            ->and($po->items_cost_cents)->toBe($po->status === SupplierOrderStatus::CANCELLED ? 0 : (int) $po->items->whereNull('cancelled_at')->sum(fn ($l) => $l->lineCostCents()));
    }
});

it('has a partly shipped store order and a fully shipped one in several parcels, consistent with the clocks', function () {
    $partial = App\Models\SellerOrder::where('status', SellerOrderStatus::PARTIALLY_SHIPPED)->with('shipments.items')->firstOrFail();
    $planner = app(ParcelPlanner::class);

    expect($partial->shipped_at)->toBeNull()
        ->and($partial->shipments)->toHaveCount(2)
        ->and($planner->remaining($partial))->not->toBeEmpty()
        ->and($planner->parcels($partial))->toHaveCount(3)
        ->and($planner->parcels($partial)->map->isShipped()->all())->toBe([true, true, false]);

    $multi = App\Models\SellerOrder::where('status', SellerOrderStatus::SHIPPED)->get()->first(fn ($so) => $so->shipments()->count() > 1);
    expect($multi)->not->toBeNull()
        ->and($planner->remaining($multi))->toBe([])
        ->and($multi->shipped_at->equalTo($multi->shipments->max('shipped_at')))->toBeTrue()
        ->and($multi->shipments->every(fn ($s) => $s->shipped_at->gte($multi->order->paid_at)))->toBeTrue();

    // every shipped or completed seller order with physical items has shipments that carry them all
    foreach (App\Models\SellerOrder::whereIn('status', ['shipped', 'delivered', 'completed'])->with('items', 'shipments.items')->get() as $so) {
        if ($so->items->where('is_digital', false)->isEmpty()) {
            continue;
        }
        expect($planner->remaining($so))->toBe([], 'seller order '.$so->id)
            ->and($so->shipments->isNotEmpty())->toBeTrue();
    }
});

it('has refunded store orders whose purchase orders reacted', function () {
    $problem = SupplierOrder::where('status', SupplierOrderStatus::PROBLEM)->firstOrFail();
    $cancelled = SupplierOrder::where('status', SupplierOrderStatus::CANCELLED)->firstOrFail();

    expect($problem->notes)->toContain('Cancel with supplier')
        ->and($problem->sellerOrder->status)->toBe(SellerOrderStatus::REFUNDED)
        ->and($problem->order->status)->toBe(OrderStatus::REFUNDED)
        ->and($cancelled->notes)->toContain('Not sent')
        ->and($cancelled->sellerOrder->status)->toBe(SellerOrderStatus::REFUNDED);
});

it('has data for the margin report', function () {
    $report = app(MarginReport::class)->forRange(now()->subDays(60), now());

    expect($report['rows']->count())->toBeGreaterThanOrEqual(5)
        ->and($report['totals']->revenueCents())->toBeGreaterThan(0)
        ->and($report['totals']->goodsCostCents)->toBeGreaterThan(0)
        ->and($report['totals']->dropshipFeesCents)->toBeGreaterThan(0)
        ->and($report['totals']->refundsCents)->toBeGreaterThan(0)
        ->and($report['totals']->marginCents())->toBeGreaterThan(0)
        ->and($report['rows']->contains(fn ($row) => $row->costsPending))->toBeTrue();
});

it('adds the dropshipping demo set to a demo site seeded before it existed, once', function () {
    $suppliers = Supplier::count();
    $orders = Order::count();
    $pos = SupplierOrder::count();

    // as on a dev server seeded before R3: no suppliers, no dropshipped listings, no store orders with purchase orders
    Order::has('supplierOrders')->get()->each->delete();
    Supplier::query()->delete();
    Listing::query()->update(['fulfillment' => 'stock', 'supplier_id' => null, 'supplier_sku' => null, 'supplier_stock' => null, 'cost_cents' => null, 'quantity' => 20]);
    expect(Supplier::count())->toBe(0)->and(SupplierOrder::count())->toBe(0);

    $this->seed(DemoSeeder::class);

    expect(Supplier::count())->toBe($suppliers)
        ->and(Order::count())->toBe($orders)
        ->and(SupplierOrder::count())->toBe($pos)
        ->and(Listing::dropshipped()->count())->toBe(5);

    // and only once
    $this->seed(DemoSeeder::class);
    expect(Supplier::count())->toBe($suppliers)->and(Order::count())->toBe($orders);
});
