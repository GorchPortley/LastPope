<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Webhook de-duplication: one row per Stripe event id that was received.
     */
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
