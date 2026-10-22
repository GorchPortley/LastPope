<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| R3 dropshipping (docs/PLAN.md 8.3): purchase orders to suppliers, created when a store order is paid.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_orders', function (Blueprint $table) {
            $table->id();
            // Human-readable, e.g. PO-7KQ2M9.
            $table->string('number')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            // App\Enums\SupplierOrderStatus
            $table->string('status')->default('pending');
            // Where the supplier ships to: the order's address as it was at purchase time.
            $table->json('shipping_address')->nullable();
            // The supplier's own order number (their confirmation, or what the portal showed).
            $table->string('supplier_reference')->nullable();
            $table->boolean('placed_manually')->default(false);
            $table->unsignedInteger('items_cost_cents')->default(0);
            // What the supplier charges us for shipping; null until known.
            $table->unsignedInteger('shipping_cost_cents')->nullable();
            $table->unsignedInteger('dropship_fee_cents')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // One purchase order per supplier per seller order: paying twice can never create a second one.
            $table->unique(['seller_order_id', 'supplier_id']);
            $table->index(['supplier_id', 'status']);
            $table->index('status');
        });

        Schema::create('supplier_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('supplier_sku')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_cost_cents')->default(0);
            // A cancelled line (refunded before the order was sent) is not bought and not shipped.
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_order_items');
        Schema::dropIfExists('supplier_orders');
    }
};
