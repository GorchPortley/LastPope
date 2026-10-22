<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Partial refunds after a payout (docs/PLAN.md 7): how much of the seller's transfer was already taken back, and how
| much still has to be taken back because Stripe refused the reversal. A NEW migration, existing rows get 0.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->unsignedInteger('reversed_cents')->default(0)->after('stripe_reversal_id');
            $table->unsignedInteger('reversal_pending_cents')->default(0)->after('reversed_cents');
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropColumn(['reversed_cents', 'reversal_pending_cents']);
        });
    }
};
