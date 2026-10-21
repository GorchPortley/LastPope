<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            // App\Enums\ListingChannel: store (SDLabs' own new drivers, admins only) or marketplace (users' kits, speakers, used gear).
            $table->string('channel')->default('marketplace');
            // App\Enums\ListingCategory: driver (store) | kit | completed_speaker | used_gear (marketplace).
            $table->string('category');
            // Link to the driver catalogue entry this listing sells (required for the store, optional for used gear).
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            // The site design a kit or a completed speaker is built from (optional).
            $table->foreignId('design_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug');
            // App\Enums\ListingCondition
            $table->string('condition')->default('new');
            $table->longText('description')->nullable();
            // Public-disk image paths: listings/{uuid}/...
            $table->json('photos')->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            // Units available; units_per_lot = how many units one "quantity" is (2 = sold as a pair).
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedSmallInteger('units_per_lot')->default(1);
            $table->char('ships_from_country', 2);
            $table->unsignedInteger('shipping_domestic_cents')->default(0);
            // null = does not ship internationally.
            $table->unsignedInteger('shipping_international_cents')->nullable();
            $table->unsignedInteger('additional_item_shipping_cents')->default(0);
            $table->unsignedSmallInteger('handling_days')->default(3);
            $table->boolean('returns_accepted')->default(false);
            $table->text('return_policy')->nullable();
            // App\Enums\ListingStatus
            $table->string('status')->default('draft');
            // Slug of the listing's forum discussion, when the forum integration is on.
            $table->string('forum_slug')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'condition']);
            $table->index(['channel', 'status']);
            $table->index('category');
            $table->index('price_cents');
            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
