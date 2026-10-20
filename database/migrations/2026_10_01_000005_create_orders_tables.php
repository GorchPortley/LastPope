<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Human-readable, e.g. SDL-7KQ2M9. Also the Stripe transfer_group.
            $table->string('number')->unique();
            // The buyer. Orders are financial records: users with orders cannot be deleted.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // App\Enums\OrderStatus
            $table->string('status')->default('pending');
            $table->char('currency', 3)->default('usd');
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('shipping_cents')->default(0);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->unsignedInteger('platform_fee_cents')->default(0);
            $table->json('shipping_address')->nullable();
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->string('stripe_charge_id')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
        });

        Schema::create('seller_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            // App\Enums\SellerOrderStatus
            $table->string('status')->default('pending');
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('shipping_cents')->default(0);
            $table->unsignedInteger('platform_fee_cents')->default(0);
            $table->unsignedInteger('payout_cents')->default(0);
            $table->string('stripe_transfer_id')->nullable()->unique();
            $table->timestamp('transferred_at')->nullable();
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('tracking_url')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status']);
            $table->index(['status', 'shipped_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_order_id')->constrained()->cascadeOnDelete();
            // Design, Driver or Listing (morph aliases design, driver, listing). No FK: the product may be deleted later.
            $table->morphs('purchasable');
            // Title and prices as they were at purchase time.
            $table->string('title');
            $table->boolean('is_digital')->default(false);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price_cents')->default(0);
            $table->unsignedInteger('shipping_cents')->default(0);
            $table->unsignedInteger('line_total_cents')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('seller_orders');
        Schema::dropIfExists('orders');
    }
};
