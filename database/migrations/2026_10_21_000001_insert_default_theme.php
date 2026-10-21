<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The theme's Blade components are only registered when an active theme row exists, and the
 * container runs `php artisan optimize` (view:cache) right after migrating, before anyone has
 * seeded. Inserting the default theme here lets a brand-new install boot and cache its views.
 * BaseDataSeeder still covers the same row and never overrides an admin's later choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('themes')->where('folder', 'blank')->exists()) {
            return;
        }

        DB::table('themes')->insert([
            'name' => 'blank',
            'folder' => 'blank',
            'active' => DB::table('themes')->where('active', 1)->exists() ? 0 : 1,
            'version' => '1.0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        //
    }
};
