<?php

namespace App\Models;

use Database\Factories\DriverSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DriverSnapshot extends Model
{
    /** @use HasFactory<DriverSnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'version',
        'snapshot_name',
        'stashed_data',
        'file_size',
        'download_path',
    ];

    protected $casts = [
        'stashed_data' => 'array',
        'file_size' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleted(function (self $snapshot) {
            if (filled($snapshot->download_path)) {
                Storage::disk('private')->delete($snapshot->download_path);
            }
        });
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
