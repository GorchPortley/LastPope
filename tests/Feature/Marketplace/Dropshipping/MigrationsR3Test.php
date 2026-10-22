<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| docs/PLAN.md 7/8: R3 adds only NEW migrations. They must apply on top of a database that has the schema and the data of
| before R3 (the dev server): the new tables and columns appear, existing rows are untouched, and every seller order that
| was shipped before gets ONE shipment covering all its physical items, so old orders keep showing their tracking.
*/

const BEFORE_R3 = '2026_10_22_000002_add_reversal_columns_to_seller_orders.php';

beforeEach(function () {
    $this->file = tempnam(sys_get_temp_dir(), 'r3-migrate-').'.sqlite';
    touch($this->file);
    config(['database.connections.r3_migrate' => ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => '', 'foreign_key_constraints' => true]]);
    $this->migrate = fn (string $command, array $options = []) => Artisan::call($command, ['--database' => 'r3_migrate', '--force' => true] + $options);
    $this->db = fn () => DB::connection('r3_migrate');
    $this->r3 = collect(glob(database_path('migrations/*.php')))->map(fn ($file) => basename($file))->filter(fn ($name) => $name > BEFORE_R3)->count();
});

afterEach(function () {
    DB::purge('r3_migrate');
    @unlink($this->file);
});

/** A database as the dev server has it: every migration but the R3 ones, with a few shipped orders. */
function siteBeforeR3(object $test): array
{
    ($test->migrate)('migrate');
    expect(($test->migrate)('migrate:rollback', ['--step' => $test->r3]))->toBe(0);

    $db = ($test->db)();
    $now = now();
    $row = fn (array $extra) => $extra + ['created_at' => $now, 'updated_at' => $now];

    $buyer = $db->table('users')->insertGetId($row(['name' => 'Buyer', 'email' => 'buyer@example.test', 'username' => 'buyer', 'password' => 'x']));
    $seller = $db->table('users')->insertGetId($row(['name' => 'Seller', 'email' => 'seller@example.test', 'username' => 'seller', 'password' => 'x']));
    $order = $db->table('orders')->insertGetId($row(['number' => 'SDL-OLD001', 'user_id' => $buyer, 'status' => 'completed', 'paid_at' => $now->copy()->subDays(30), 'total_cents' => 30000]));

    $sellerOrder = fn (string $status, array $extra = []) => $db->table('seller_orders')->insertGetId($row([
        'order_id' => $order, 'seller_id' => $seller, 'status' => $status, 'subtotal_cents' => 10000, 'shipping_cents' => 500, 'payout_cents' => 9500,
    ] + $extra));
    $item = fn (int $sellerOrderId, bool $digital, int $quantity) => $db->table('order_items')->insertGetId($row([
        'order_id' => $order, 'seller_order_id' => $sellerOrderId, 'purchasable_type' => $digital ? 'design' : 'listing', 'purchasable_id' => 1,
        'title' => $digital ? 'A design' : 'A driver', 'is_digital' => $digital, 'quantity' => $quantity, 'unit_price_cents' => 5000, 'line_total_cents' => 5000 * $quantity,
    ]));

    $shippedAt = $now->copy()->subDays(20);

    $ids = [
        // shipped, with tracking: gets a shipment with both physical items (a digital line travels in no parcel)
        'shipped' => $sellerOrder('shipped', ['carrier' => 'UPS', 'tracking_number' => '1Z1', 'tracking_url' => 'https://ups.example/1Z1', 'shipped_at' => $shippedAt]),
        'completed' => $sellerOrder('completed', ['carrier' => 'USPS', 'tracking_number' => '9400', 'shipped_at' => $shippedAt->copy()->addDay(), 'delivered_at' => $now->copy()->subDays(10)]),
        'delivered' => $sellerOrder('delivered', ['carrier' => 'DHL', 'tracking_number' => null, 'shipped_at' => $shippedAt]), // carrier only
        // not covered: no tracking at all, not shipped yet, refunded
        'untracked' => $sellerOrder('completed'),
        'waiting' => $sellerOrder('awaiting_shipment', ['carrier' => 'UPS', 'tracking_number' => 'NOT-YET']),
        'refunded' => $sellerOrder('refunded', ['carrier' => 'UPS', 'tracking_number' => '1ZREF', 'shipped_at' => $shippedAt]),
    ];

    $items = [];
    foreach (['shipped', 'completed', 'delivered', 'refunded'] as $name) {
        $items[$name] = [$item($ids[$name], false, 2), $item($ids[$name], false, 1), $item($ids[$name], true, 1)];
    }

    return compact('db', 'ids', 'items', 'shippedAt');
}

it('adds the dropshipping tables and columns to a database that has the schema and data of before R3', function () {
    $x = siteBeforeR3($this);
    $db = $x['db'];
    $schema = Schema::connection('r3_migrate');

    expect($schema->hasTable('suppliers'))->toBeFalse()->and($schema->hasTable('shipments'))->toBeFalse()
        ->and($schema->hasColumn('listings', 'fulfillment'))->toBeFalse()
        ->and($schema->hasColumn('order_items', 'fulfillment_source'))->toBeFalse();
    $before = $db->table('seller_orders')->orderBy('id')->get();

    // the deploy: php artisan migrate --force
    expect(($this->migrate)('migrate'))->toBe(0);

    expect($schema->hasTable('suppliers'))->toBeTrue()
        ->and($schema->hasTable('supplier_orders'))->toBeTrue()
        ->and($schema->hasTable('supplier_order_items'))->toBeTrue()
        ->and($schema->hasTable('shipments'))->toBeTrue()
        ->and($schema->hasTable('shipment_items'))->toBeTrue()
        ->and($schema->hasColumns('listings', ['fulfillment', 'supplier_id', 'supplier_sku', 'cost_cents', 'supplier_stock', 'supplier_stock_synced_at']))->toBeTrue()
        ->and($schema->hasColumns('order_items', ['fulfillment_source', 'supplier_id', 'unit_cost_cents']))->toBeTrue()
        ->and($schema->hasColumns('suppliers', [
            'name', 'order_email', 'lead_time_min_days', 'lead_time_max_days', 'dropship_fee_cents', 'blind_dropship', 'auto_send_purchase_orders',
            'shipping_domestic_cents', 'shipping_international_cents', 'additional_item_shipping_cents', 'free_shipping_over_cents', 'active',
            'feed_url', 'feed_file_path', 'feed_sku_column', 'feed_quantity_column', 'feed_cost_column', 'feed_last_synced_at', 'feed_last_error',
        ]))->toBeTrue()
        ->and($schema->hasColumns('supplier_orders', ['number', 'order_id', 'seller_order_id', 'supplier_id', 'status', 'shipping_address', 'supplier_reference', 'items_cost_cents', 'shipping_cost_cents', 'dropship_fee_cents', 'sent_at', 'confirmed_at', 'cancelled_at', 'notes']))->toBeTrue()
        ->and($schema->hasColumns('shipments', ['seller_order_id', 'supplier_order_id', 'carrier', 'tracking_number', 'tracking_url', 'shipped_at']))->toBeTrue();

    // nothing of the old rows changed
    expect($db->table('seller_orders')->orderBy('id')->get()->all())->toEqual($before->all())
        ->and($db->table('orders')->count())->toBe(1)
        ->and($db->table('order_items')->count())->toBe(12);

    // existing rows behave as before: own stock, no snapshot
    $item = $db->table('order_items')->first();
    expect($item->fulfillment_source)->toBeNull()->and($item->supplier_id)->toBeNull()->and($item->unit_cost_cents)->toBeNull();
});

it('gives every seller order that was shipped before one shipment with all its physical items', function () {
    $x = siteBeforeR3($this);
    $db = $x['db'];
    ($this->migrate)('migrate');

    $shipments = $db->table('shipments')->orderBy('seller_order_id')->get()->keyBy('seller_order_id');

    // shipped, completed and delivered orders that have a carrier or tracking: one each; the others none
    expect($shipments->keys()->all())->toBe([$x['ids']['shipped'], $x['ids']['completed'], $x['ids']['delivered']]);

    $shipped = $shipments[$x['ids']['shipped']];
    expect($shipped->carrier)->toBe('UPS')
        ->and($shipped->tracking_number)->toBe('1Z1')
        ->and($shipped->tracking_url)->toBe('https://ups.example/1Z1')
        ->and($shipped->supplier_order_id)->toBeNull()
        ->and((string) $shipped->shipped_at)->toBe((string) $x['shippedAt']);

    $completed = $shipments[$x['ids']['completed']];
    expect($completed->carrier)->toBe('USPS')->and($completed->tracking_number)->toBe('9400')->and($completed->tracking_url)->toBeNull();
    expect($shipments[$x['ids']['delivered']]->carrier)->toBe('DHL')->and($shipments[$x['ids']['delivered']]->tracking_number)->toBeNull();

    // the two physical items travel in it with their quantities, the digital one does not
    $inShipped = $db->table('shipment_items')->where('shipment_id', $shipped->id)->orderBy('order_item_id')->get();
    expect($inShipped->pluck('order_item_id')->all())->toBe([$x['items']['shipped'][0], $x['items']['shipped'][1]])
        ->and($inShipped->pluck('quantity')->all())->toBe([2, 1]);

    // three shipped seller orders, two physical items each
    expect($db->table('shipment_items')->count())->toBe(6);
});

it('can be run again without making a second shipment, and rolls back cleanly', function () {
    $x = siteBeforeR3($this);
    $db = $x['db'];
    ($this->migrate)('migrate');
    $count = $db->table('shipments')->count();

    // the backfill on its own, a second time (as a half-finished deploy would)
    $migration = require database_path('migrations/2026_10_23_000006_backfill_shipments_from_seller_orders.php');
    $default = config('database.default');
    DB::setDefaultConnection('r3_migrate');
    try {
        $migration->up();
    } finally {
        DB::setDefaultConnection($default);
    }
    expect($db->table('shipments')->count())->toBe($count)->and($db->table('shipment_items')->count())->toBe(6);

    expect(($this->migrate)('migrate:rollback', ['--step' => $this->r3]))->toBe(0);
    $schema = Schema::connection('r3_migrate');
    expect($schema->hasTable('shipments'))->toBeFalse()
        ->and($schema->hasTable('suppliers'))->toBeFalse()
        ->and($schema->hasColumn('listings', 'fulfillment'))->toBeFalse()
        ->and($schema->hasColumn('order_items', 'unit_cost_cents'))->toBeFalse()
        // the tracking still lives on the seller orders
        ->and($db->table('seller_orders')->where('id', $x['ids']['shipped'])->value('tracking_number'))->toBe('1Z1');

    // and forward again
    expect(($this->migrate)('migrate'))->toBe(0)->and($db->table('shipments')->count())->toBe($count);
});

it('keeps the R3 migrations as new files that sort after the last migration of before R3', function () {
    $r3 = [
        '2026_10_23_000001_create_suppliers_table.php',
        '2026_10_23_000002_add_dropshipping_columns_to_listings.php',
        '2026_10_23_000003_add_fulfillment_columns_to_order_items.php',
        '2026_10_23_000004_create_supplier_orders_tables.php',
        '2026_10_23_000005_create_shipments_tables.php',
        '2026_10_23_000006_backfill_shipments_from_seller_orders.php',
    ];

    foreach ($r3 as $name) {
        expect(file_exists(database_path('migrations/'.$name)))->toBeTrue()->and($name > BEFORE_R3)->toBeTrue();
    }
});

it('lets the new tables work on top of the old data', function () {
    $x = siteBeforeR3($this);
    $db = $x['db'];
    ($this->migrate)('migrate');
    $now = now();

    $supplier = $db->table('suppliers')->insertGetId(['name' => 'Acme', 'created_at' => $now, 'updated_at' => $now]);
    $po = $db->table('supplier_orders')->insertGetId([
        'number' => 'PO-OLD111', 'order_id' => 1, 'seller_order_id' => $x['ids']['waiting'], 'supplier_id' => $supplier, 'created_at' => $now, 'updated_at' => $now,
    ]);

    expect($db->table('supplier_orders')->find($po)->status)->toBe('pending')
        ->and($db->table('suppliers')->find($supplier)->blind_dropship)->toBe(1)
        ->and($db->table('suppliers')->find($supplier)->feed_sku_column)->toBe('sku')
        ->and(fn () => $db->table('supplier_orders')->insert([
            'number' => 'PO-OLD222', 'order_id' => 1, 'seller_order_id' => $x['ids']['waiting'], 'supplier_id' => $supplier, 'created_at' => $now, 'updated_at' => $now,
        ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class)
        ->and($db->table('listings')->count())->toBe(0);
});
