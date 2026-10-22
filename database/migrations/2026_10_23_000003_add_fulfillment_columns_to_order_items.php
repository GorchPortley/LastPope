<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| R3 dropshipping (docs/PLAN.md 8.1): where each paid line is fulfilled from, decided at checkout, and what a unit cost us then.
| Existing rows keep NULL, which means "own stock / not a store line", exactly as before.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // App\Enums\FulfillmentSource: stock | supplier (null = not tracked: digital, marketplace, or before R3)
            $table->string('fulfillment_source')->nullable()->after('is_digital');
            $table->foreignId('supplier_id')->nullable()->after('fulfillment_source')->constrained()->nullOnDelete();
            // Snapshot of listings.cost_cents at checkout (margin report).
            $table->unsignedInteger('unit_cost_cents')->nullable()->after('unit_price_cents');

            $table->index(['fulfillment_source', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['fulfillment_source', 'supplier_id']);
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['fulfillment_source', 'unit_cost_cents']);
        });
    }
};
