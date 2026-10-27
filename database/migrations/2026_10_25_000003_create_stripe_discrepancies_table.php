<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Differences between the site's records and Stripe's ledger found by the daily reconciliation (docs/PLAN.md 11, R6.3).
| One row per kind + key: a difference that is found again is the same row, one that is fixed is resolved by itself.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_discrepancies', function (Blueprint $table) {
            $table->id();
            // App\Enums\DiscrepancyKind and what it is about ("order:12", "charge:ch_...", "payout:po_...").
            $table->string('kind');
            $table->string('key');
            $table->boolean('livemode')->default(false);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('seller_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stripe_id')->nullable();
            $table->string('account')->nullable();
            // What the site says and what Stripe says, in cents, where the difference is an amount.
            $table->bigInteger('expected_cents')->nullable();
            $table->bigInteger('actual_cents')->nullable();
            $table->char('currency', 3)->default('usd');
            $table->text('details');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            // Fixed by itself ('auto') or closed by an admin ('manual', with a note).
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution')->nullable();
            $table->text('note')->nullable();
            // The admins were mailed about it at this time.
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'key']);
            $table->index('resolved_at');
            $table->index('order_id');
            $table->index('seller_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_discrepancies');
    }
};
