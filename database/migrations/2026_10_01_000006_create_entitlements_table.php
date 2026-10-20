<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The digital product the user may download (Design or Driver).
            $table->morphs('entitleable');
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            // App\Enums\EntitlementSource
            $table->string('source');
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'entitleable_type', 'entitleable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlements');
    }
};
