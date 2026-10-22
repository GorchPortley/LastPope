<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| R3 dropshipping (docs/PLAN.md 8): the suppliers behind the SDLabs store. A NEW migration; existing tables are untouched.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            // Where purchase orders are e-mailed to.
            $table->string('order_email')->nullable();
            $table->string('website')->nullable();
            $table->string('account_number')->nullable();
            $table->char('ships_from_country', 2)->default('US');
            // What the supplier promises, in business days. Shown to buyers as a range, never with the supplier's name.
            $table->unsignedSmallInteger('lead_time_min_days')->default(3);
            $table->unsignedSmallInteger('lead_time_max_days')->default(7);
            // What the supplier charges us per purchase order.
            $table->unsignedInteger('dropship_fee_cents')->default(0);
            // No supplier branding, invoices or prices in the parcel.
            $table->boolean('blind_dropship')->default(true);
            $table->boolean('auto_send_purchase_orders')->default(false);
            // What WE charge the buyer for this supplier's parcel (null international = domestic only).
            $table->unsignedInteger('shipping_domestic_cents')->default(0);
            $table->unsignedInteger('shipping_international_cents')->nullable();
            $table->unsignedInteger('additional_item_shipping_cents')->default(0);
            // Parcel items total at or above this ships free (null = never).
            $table->unsignedInteger('free_shipping_over_cents')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            // Stock feed: a CSV over HTTP, or an uploaded file on the private disk.
            $table->string('feed_url', 500)->nullable();
            $table->string('feed_file_path', 500)->nullable();
            $table->string('feed_sku_column')->default('sku');
            $table->string('feed_quantity_column')->default('quantity');
            // Null = the feed carries no cost.
            $table->string('feed_cost_column')->nullable();
            $table->timestamp('feed_last_synced_at')->nullable();
            $table->text('feed_last_error')->nullable();
            $table->timestamps();

            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
