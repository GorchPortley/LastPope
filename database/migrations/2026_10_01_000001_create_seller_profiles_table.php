<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('shop_name');
            $table->string('slug')->unique();
            $table->text('bio')->nullable();
            $table->char('country', 2);
            // Stripe Connect Express account (acct_...).
            $table->string('stripe_account_id')->nullable()->unique();
            $table->boolean('charges_enabled')->default(false);
            $table->boolean('payouts_enabled')->default(false);
            $table->boolean('details_submitted')->default(false);
            // Per-seller override of marketplace.fee_bps (basis points), null = use the default.
            $table->unsignedSmallInteger('commission_bps')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_profiles');
    }
};
