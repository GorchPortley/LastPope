<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Stable folder name for this driver's files (drivers/{storage_key}/...).
            $table->uuid('storage_key')->unique();
            $table->string('brand');
            $table->string('model');
            $table->json('card_image')->nullable();
            $table->string('tag')->nullable();
            $table->boolean('active')->default(false);
            $table->string('category');
            $table->integer('size');
            $table->string('impedance');
            $table->integer('power');
            // Price of the driver's measurement data pack (digital), and the reference retail price of the physical driver.
            $table->unsignedInteger('data_price_cents')->default(0);
            $table->unsignedInteger('msrp_cents')->nullable();
            $table->string('link')->nullable();
            $table->text('summary')->nullable();
            $table->longText('description')->nullable();
            $table->json('factory_specs')->nullable();
            $table->json('frequency_files')->nullable();
            $table->json('impedance_files')->nullable();
            $table->json('other_files')->nullable();
            $table->boolean('official')->default(false);
            $table->string('forum_slug')->nullable();
            $table->timestamps();

            $table->index(['active', 'category']);
            $table->index(['brand', 'model']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
