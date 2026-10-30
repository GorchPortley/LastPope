<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Data step, no schema change: queue the upload of every user's avatar to the forum once, on the deploy that added
 * forum avatars (the queue worker does the uploads; nothing happens when the forum is off). Never fails the deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            Artisan::call('forum:sync-avatars');
        } catch (\Throwable $e) {
            Log::warning('Could not queue the forum avatar sync; run php artisan forum:sync-avatars by hand.', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        // Nothing to undo.
    }
};
