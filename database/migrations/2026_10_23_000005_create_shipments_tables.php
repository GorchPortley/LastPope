<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| R3 dropshipping (docs/PLAN.md 8.3): parcels. Every shipped seller order has one or more shipments from now on
| (the tracking columns on seller_orders stay as a mirror of the latest one).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_order_id')->constrained()->cascadeOnDelete();
            // The purchase order this parcel came from, for dropshipped lines.
            $table->foreignId('supplier_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamps();

            $table->index(['seller_order_id', 'shipped_at']);
        });

        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['shipment_id', 'order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');
    }
};
