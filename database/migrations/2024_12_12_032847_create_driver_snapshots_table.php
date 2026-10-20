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
        Schema::create('driver_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->string('version');
            $table->string('snapshot_name');
            // manifest.json: the driver's data as it was when the snapshot was taken, plus the file list.
            $table->json('stashed_data');
            $table->unsignedBigInteger('file_size')->nullable();
            // ZIP on the private disk: drivers/{storage_key}/snapshots/{slug}-{version}.zip
            $table->string('download_path');
            $table->timestamps();

            $table->unique(['driver_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_snapshots');
    }
};
