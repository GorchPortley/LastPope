<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| R3 dropshipping (docs/PLAN.md 8.1): how a store listing is fulfilled and what one unit costs us.
| Every existing listing becomes fulfillment = stock, which is exactly how it behaved before.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // App\Enums\FulfillmentMode: stock | dropship | stock_then_dropship
            $table->string('fulfillment')->default('stock')->after('status');
            $table->foreignId('supplier_id')->nullable()->after('fulfillment')->constrained()->nullOnDelete();
            $table->string('supplier_sku')->nullable()->after('supplier_id');
            // What one unit costs us: the supplier's price (dropship) or our purchase cost (stock). Never shown to buyers.
            $table->unsignedInteger('cost_cents')->nullable()->after('supplier_sku');
            // From the supplier's feed; null = unknown, which counts as available.
            $table->unsignedInteger('supplier_stock')->nullable()->after('cost_cents');
            $table->timestamp('supplier_stock_synced_at')->nullable()->after('supplier_stock');

            $table->index(['supplier_id', 'supplier_sku']);
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['supplier_id', 'supplier_sku']);
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['fulfillment', 'supplier_sku', 'cost_cents', 'supplier_stock', 'supplier_stock_synced_at']);
        });
    }
};
