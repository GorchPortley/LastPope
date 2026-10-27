<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The Stripe ledger mirror (docs/PLAN.md 11, R6.1). A NEW migration on purpose: the dev server holds data, so existing
| migrations are never edited. Money is signed integer cents (a balance transaction can be negative).
|
| `account` is NULL for the platform account and acct_... for a connected (seller) account. `livemode` keeps test and live
| data apart, so switching the keys never mixes them.
*/
return new class extends Migration
{
    public function up(): void
    {
        // Stripe's balance transactions, one row per txn_..., written by LedgerSync.
        Schema::create('stripe_balance_transactions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('account')->nullable();
            $table->boolean('livemode')->default(false);
            $table->string('type');
            $table->string('reporting_category')->nullable();
            $table->bigInteger('amount_cents');
            $table->bigInteger('fee_cents')->default(0);
            $table->bigInteger('net_cents');
            $table->char('currency', 3);
            // Stripe's own times (`created`, `available_on`); created_at / updated_at say when the row was mirrored.
            $table->timestamp('stripe_created_at');
            $table->timestamp('available_on');
            $table->text('description')->nullable();
            $table->json('fee_details')->nullable();
            // The object behind the transaction (expanded when synced) and the ids it points at.
            $table->string('source_id')->nullable();
            $table->string('source_type')->nullable();
            $table->string('charge_id')->nullable();
            $table->string('payment_intent_id')->nullable();
            $table->string('transfer_id')->nullable();
            $table->string('reversal_id')->nullable();
            $table->string('refund_id')->nullable();
            $table->string('dispute_id')->nullable();
            $table->string('payout_id')->nullable();
            // The other side of a transfer: the connected account it went to.
            $table->string('counterparty')->nullable();
            $table->string('transfer_group')->nullable();
            $table->json('source_metadata')->nullable();
            // Links found by matching (App\Enums\LedgerLink): linked to an order, should be but is not, or not about orders.
            $table->string('link_status', 16)->default('other');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('seller_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['account', 'stripe_created_at']);
            $table->index(['livemode', 'stripe_created_at']);
            $table->index('type');
            $table->index('link_status');
            $table->index('source_id');
            $table->index('charge_id');
            $table->index('transfer_id');
            $table->index('reversal_id');
            $table->index('dispute_id');
            $table->index('order_id');
            $table->index('seller_order_id');
            $table->index('seller_id');
        });

        // Payouts to a bank account: the platform's own and the connected accounts'.
        Schema::create('stripe_payouts', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('account')->nullable();
            $table->boolean('livemode')->default(false);
            $table->bigInteger('amount_cents');
            $table->char('currency', 3);
            // Stripe's status: pending, in_transit, paid, failed, canceled.
            $table->string('status');
            $table->string('type')->nullable();
            $table->string('method')->nullable();
            $table->boolean('automatic')->default(true);
            $table->timestamp('stripe_created_at');
            $table->timestamp('arrival_date')->nullable();
            $table->text('description')->nullable();
            // "Chase ••••6789": the bank account or card it goes to.
            $table->string('destination_label')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->string('balance_transaction_id')->nullable();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['account', 'arrival_date']);
            $table->index(['livemode', 'status']);
            $table->index('seller_id');
        });

        // The latest balance per account and currency. in_transit is the sum of the payouts on their way to the bank.
        Schema::create('stripe_balances', function (Blueprint $table) {
            $table->id();
            // 'platform' or acct_...: never NULL, so the unique index below holds on every database.
            $table->string('account_key');
            $table->string('account')->nullable();
            $table->boolean('livemode')->default(false);
            $table->char('currency', 3);
            $table->bigInteger('available_cents')->default(0);
            $table->bigInteger('pending_cents')->default(0);
            $table->bigInteger('in_transit_cents')->default(0);
            $table->bigInteger('connect_reserved_cents')->default(0);
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['account_key', 'livemode', 'currency']);
        });

        // Where the sync of an account stands. synced_through is the start of the last complete pass; the resume_* columns
        // remember a pass that stopped early (page limit) so the next run continues instead of starting over.
        Schema::create('stripe_sync_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('account_key');
            $table->string('account')->nullable();
            $table->boolean('livemode')->default(false);
            $table->timestamp('synced_through')->nullable();
            $table->string('resume_after')->nullable();
            $table->timestamp('resume_since')->nullable();
            $table->timestamp('resume_through')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->unsignedInteger('failures')->default(0);
            $table->timestamps();

            $table->unique(['account_key', 'livemode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_sync_cursors');
        Schema::dropIfExists('stripe_balances');
        Schema::dropIfExists('stripe_payouts');
        Schema::dropIfExists('stripe_balance_transactions');
    }
};
