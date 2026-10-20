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
        Schema::create('designs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Stable folder name for this design's files (designs/{storage_key}/...), set when the create form mounts.
            $table->uuid('storage_key')->unique();
            $table->string('name')->nullable();
            $table->string('tag')->nullable();
            $table->json('card_image')->nullable();
            $table->boolean('active')->default(false);
            $table->string('category');
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedInteger('build_cost_cents')->default(0);
            $table->integer('impedance')->default(4)->nullable();
            $table->integer('power')->nullable();
            $table->text('summary')->nullable();
            $table->longText('description')->nullable();
            $table->json('bill_of_materials')->nullable();
            $table->json('frd_files')->nullable();
            $table->json('enclosure_files')->nullable();
            $table->json('electronic_files')->nullable();
            $table->json('design_other_files')->nullable();
            $table->boolean('official')->default(false);
            $table->string('forum_slug')->nullable();
            $table->timestamps();

            $table->index(['active', 'category']);
            $table->index('price_cents');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('designs');
    }
};
