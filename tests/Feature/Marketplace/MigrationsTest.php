<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| docs/PLAN.md 7: from R2 on existing migrations are never edited, every schema change is a NEW migration, because the dev
| server holds data and deploys run `php artisan migrate --force`. This checks that the R2 migrations apply on top of the
| schema (and the rows) of the state before R2 - not only on a fresh database.
*/

beforeEach(function () {
    $this->file = tempnam(sys_get_temp_dir(), 'r2-migrate-').'.sqlite';
    touch($this->file);
    config(['database.connections.r2_migrate' => ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => '', 'foreign_key_constraints' => true]]);
    $this->migrate = fn (string $command, array $options = []) => Artisan::call($command, ['--database' => 'r2_migrate', '--force' => true] + $options);
    $this->db = fn () => DB::connection('r2_migrate');
    // Every migration file newer than the last one of before R2: the two R2 migrations and whatever was added after them (R3...).
    $this->sinceR2 = collect(glob(database_path('migrations/*.php')))->map(fn ($file) => basename($file))->filter(fn ($name) => $name > '2026_10_21_000001_insert_default_theme.php')->count();
});

afterEach(function () {
    DB::purge('r2_migrate');
    @unlink($this->file);
});

it('adds the problem report tables and seller order columns to a database that has the schema and data of before R2', function () {
    ($this->migrate)('migrate');

    // Back to the schema the dev server has: everything but the R2 migrations (and those after them).
    expect(($this->migrate)('migrate:rollback', ['--step' => $this->sinceR2]))->toBe(0);
    $schema = Schema::connection('r2_migrate');
    expect($schema->hasTable('order_issues'))->toBeFalse()
        ->and($schema->hasTable('order_issue_messages'))->toBeFalse()
        ->and($schema->hasColumn('seller_orders', 'reversed_cents'))->toBeFalse()
        ->and($schema->hasTable('seller_orders'))->toBeTrue();

    // A site with data: a buyer, a seller, a paid order that was already transferred.
    $db = ($this->db)();
    $buyer = $db->table('users')->insertGetId(['name' => 'Buyer', 'email' => 'buyer@example.test', 'username' => 'buyer', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    $seller = $db->table('users')->insertGetId(['name' => 'Seller', 'email' => 'seller@example.test', 'username' => 'seller', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    $order = $db->table('orders')->insertGetId(['number' => 'SDL-OLD111', 'user_id' => $buyer, 'status' => 'paid', 'paid_at' => now(), 'total_cents' => 6900, 'created_at' => now(), 'updated_at' => now()]);
    $sellerOrder = $db->table('seller_orders')->insertGetId([
        'order_id' => $order, 'seller_id' => $seller, 'status' => 'shipped', 'subtotal_cents' => 6000, 'shipping_cents' => 900,
        'platform_fee_cents' => 600, 'payout_cents' => 6300, 'stripe_transfer_id' => 'tr_old', 'transferred_at' => now(), 'refunded_cents' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // The deploy: php artisan migrate --force
    expect(($this->migrate)('migrate'))->toBe(0);

    expect($schema->hasTable('order_issues'))->toBeTrue()
        ->and($schema->hasTable('order_issue_messages'))->toBeTrue()
        ->and($schema->hasColumns('seller_orders', ['reversed_cents', 'reversal_pending_cents']))->toBeTrue()
        ->and($schema->hasColumns('order_issues', [
            'order_id', 'seller_order_id', 'buyer_id', 'seller_id', 'reason', 'status', 'resolution', 'offer_type', 'offer_cents', 'refunded_cents',
            'return_carrier', 'return_tracking_number', 'stripe_dispute_id', 'escalated_at', 'resolved_at', 'resolved_by', 'open_seller_order_id',
        ]))->toBeTrue()
        ->and($schema->hasColumns('order_issue_messages', ['order_issue_id', 'author_id', 'body', 'attachments', 'internal']))->toBeTrue();

    // Existing rows are untouched and get zeros for the new columns.
    $row = $db->table('seller_orders')->find($sellerOrder);
    expect($row->payout_cents)->toBe(6300)->and($row->stripe_transfer_id)->toBe('tr_old')
        ->and($row->reversed_cents)->toBe(0)->and($row->reversal_pending_cents)->toBe(0)
        ->and($db->table('orders')->count())->toBe(1);

    // The new tables work on top of the old data.
    $issue = $db->table('order_issues')->insertGetId([
        'order_id' => $order, 'seller_order_id' => $sellerOrder, 'buyer_id' => $buyer, 'seller_id' => $seller, 'reason' => 'damaged',
        'open_seller_order_id' => $sellerOrder, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $db->table('order_issue_messages')->insert(['order_issue_id' => $issue, 'author_id' => $buyer, 'body' => 'hello', 'created_at' => now(), 'updated_at' => now()]);
    expect($db->table('order_issues')->find($issue)->status)->toBe('open')
        ->and(fn () => $db->table('order_issues')->insert([
            'order_id' => $order, 'seller_order_id' => $sellerOrder, 'buyer_id' => $buyer, 'seller_id' => $seller, 'reason' => 'other',
            'open_seller_order_id' => $sellerOrder, 'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);

    // Running it again changes nothing.
    expect(($this->migrate)('migrate'))->toBe(0)
        ->and(trim(Artisan::output()))->toContain('Nothing to migrate');
});

it('rolls the R2 migrations back cleanly', function () {
    ($this->migrate)('migrate');

    expect(($this->migrate)('migrate:rollback', ['--step' => $this->sinceR2]))->toBe(0);
    $schema = Schema::connection('r2_migrate');

    expect($schema->hasTable('order_issues'))->toBeFalse()
        ->and($schema->hasTable('order_issue_messages'))->toBeFalse()
        ->and($schema->hasColumn('seller_orders', 'reversal_pending_cents'))->toBeFalse()
        ->and($schema->hasColumn('seller_orders', 'payout_cents'))->toBeTrue();
});

it('keeps the R2 migrations as new files that sort after the last migration of before R2', function () {
    $lastBeforeR2 = '2026_10_21_000001_insert_default_theme.php';
    $r2 = ['2026_10_22_000001_create_order_issues_tables.php', '2026_10_22_000002_add_reversal_columns_to_seller_orders.php'];

    foreach ($r2 as $name) {
        expect(file_exists(database_path('migrations/'.$name)))->toBeTrue()
            ->and($name > $lastBeforeR2)->toBeTrue();
    }
});
