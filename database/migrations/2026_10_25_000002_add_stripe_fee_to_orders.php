<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The real Stripe fee of an order's charge (docs/PLAN.md 11, R6.4), read from the charge's balance transaction by the
| ledger sync. NULL = not known yet: the margin report then falls back to an estimate. Existing rows stay NULL.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('stripe_fee_cents')->nullable()->after('platform_fee_cents');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('stripe_fee_cents');
        });
    }
};
