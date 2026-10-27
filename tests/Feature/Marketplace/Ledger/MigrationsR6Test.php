<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| docs/PLAN.md 7/11: R6 adds only NEW migrations (2026_10_25_*). They must apply on top of the schema and the data of before R6 (the
| dev server): the ledger tables and the discrepancy table appear, orders get a nullable stripe_fee_cents, existing rows are untouched.
*/

const BEFORE_R6 = '2026_10_23_000006_backfill_shipments_from_seller_orders.php';

beforeEach(function () {
    $this->file = tempnam(sys_get_temp_dir(), 'r6-migrate-').'.sqlite';
    touch($this->file);
    config(['database.connections.r6_migrate' => ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => '', 'foreign_key_constraints' => true]]);
    $this->migrate = fn (string $command, array $options = []) => Artisan::call($command, ['--database' => 'r6_migrate', '--force' => true] + $options);
    $this->db = fn () => DB::connection('r6_migrate');
    $this->r6 = collect(glob(database_path('migrations/*.php')))->map(fn ($file) => basename($file))->filter(fn ($name) => $name > BEFORE_R6)->count();
});

afterEach(function () {
    DB::purge('r6_migrate');
    @unlink($this->file);
});

it('adds the ledger tables, the discrepancies and the fee column to a site that has the schema and data of before R6', function () {
    ($this->migrate)('migrate');
    expect(($this->migrate)('migrate:rollback', ['--step' => $this->r6]))->toBe(0);
    $schema = Schema::connection('r6_migrate');

    expect($schema->hasTable('stripe_balance_transactions'))->toBeFalse()->and($schema->hasTable('stripe_discrepancies'))->toBeFalse()
        ->and($schema->hasColumn('orders', 'stripe_fee_cents'))->toBeFalse()->and($schema->hasTable('orders'))->toBeTrue();

    // a site with data
    $db = ($this->db)();
    $now = now();
    $buyer = $db->table('users')->insertGetId(['name' => 'Buyer', 'email' => 'buyer@example.test', 'username' => 'buyer', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    $order = $db->table('orders')->insertGetId(['number' => 'SDL-OLD111', 'user_id' => $buyer, 'status' => 'paid', 'paid_at' => $now, 'total_cents' => 6900, 'stripe_charge_id' => 'ch_old', 'created_at' => $now, 'updated_at' => $now]);

    // the deploy: php artisan migrate --force
    expect(($this->migrate)('migrate'))->toBe(0);

    expect($schema->hasTable('stripe_balance_transactions'))->toBeTrue()->and($schema->hasTable('stripe_payouts'))->toBeTrue()
        ->and($schema->hasTable('stripe_balances'))->toBeTrue()->and($schema->hasTable('stripe_sync_cursors'))->toBeTrue()->and($schema->hasTable('stripe_discrepancies'))->toBeTrue()
        ->and($schema->hasColumns('stripe_balance_transactions', [
            'id', 'account', 'livemode', 'type', 'reporting_category', 'amount_cents', 'fee_cents', 'net_cents', 'currency', 'stripe_created_at', 'available_on',
            'source_id', 'source_type', 'charge_id', 'transfer_id', 'link_status', 'order_id', 'seller_order_id', 'seller_id',
        ]))->toBeTrue()
        ->and($schema->hasColumns('stripe_payouts', ['id', 'account', 'livemode', 'amount_cents', 'status', 'arrival_date', 'failure_code', 'failure_message']))->toBeTrue()
        ->and($schema->hasColumns('stripe_balances', ['account_key', 'livemode', 'currency', 'available_cents', 'pending_cents', 'in_transit_cents', 'refreshed_at']))->toBeTrue()
        ->and($schema->hasColumns('stripe_sync_cursors', ['account_key', 'livemode', 'synced_through', 'resume_after', 'last_error']))->toBeTrue()
        ->and($schema->hasColumns('stripe_discrepancies', ['kind', 'key', 'expected_cents', 'actual_cents', 'details', 'first_seen_at', 'last_seen_at', 'resolved_at', 'resolved_by', 'note']))->toBeTrue()
        ->and($schema->hasColumn('orders', 'stripe_fee_cents'))->toBeTrue();

    // existing rows are untouched, the fee is unknown
    $row = $db->table('orders')->find($order);
    expect($row->stripe_charge_id)->toBe('ch_old')->and($row->total_cents)->toBe(6900)->and($row->stripe_fee_cents)->toBeNull();

    // the new tables work on top of the old data, and their unique keys hold
    $db->table('stripe_balance_transactions')->insert([
        'id' => 'txn_1', 'type' => 'charge', 'amount_cents' => -6900, 'net_cents' => -6900, 'currency' => 'usd', 'stripe_created_at' => $now, 'available_on' => $now,
        'order_id' => $order, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $balance = ['account_key' => 'platform', 'livemode' => false, 'currency' => 'usd', 'created_at' => $now, 'updated_at' => $now];
    $db->table('stripe_balances')->insert($balance);
    $discrepancy = ['kind' => 'charge_missing', 'key' => 'order:1', 'details' => 'x', 'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now];
    $db->table('stripe_discrepancies')->insert($discrepancy);

    expect($db->table('stripe_balance_transactions')->find('txn_1')->link_status)->toBe('other')
        ->and($db->table('stripe_balances')->first()->available_cents)->toBe(0)
        ->and(fn () => $db->table('stripe_balances')->insert($balance))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class)
        ->and(fn () => $db->table('stripe_discrepancies')->insert($discrepancy))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);

    // deleting the order keeps the ledger line (nulled link), as for a record that belongs to Stripe
    $db->table('orders')->where('id', $order)->delete();
    expect($db->table('stripe_balance_transactions')->find('txn_1')->order_id)->toBeNull();

    // running it again changes nothing
    expect(($this->migrate)('migrate'))->toBe(0)->and(trim(Artisan::output()))->toContain('Nothing to migrate');
});

it('rolls the R6 migrations back cleanly', function () {
    ($this->migrate)('migrate');

    expect(($this->migrate)('migrate:rollback', ['--step' => $this->r6]))->toBe(0);
    $schema = Schema::connection('r6_migrate');

    foreach (['stripe_balance_transactions', 'stripe_payouts', 'stripe_balances', 'stripe_sync_cursors', 'stripe_discrepancies'] as $table) {
        expect($schema->hasTable($table))->toBeFalse();
    }
    expect($schema->hasColumn('orders', 'stripe_fee_cents'))->toBeFalse()->and($schema->hasColumn('orders', 'total_cents'))->toBeTrue();
});

it('keeps the R6 migrations as new files that sort after the last migration of before R6', function () {
    foreach (['2026_10_25_000001_create_stripe_ledger_tables.php', '2026_10_25_000002_add_stripe_fee_to_orders.php', '2026_10_25_000003_create_stripe_discrepancies_table.php'] as $name) {
        expect(file_exists(database_path('migrations/'.$name)))->toBeTrue()->and($name > BEFORE_R6)->toBeTrue();
    }

    expect($this->r6)->toBeGreaterThanOrEqual(3);
});
