<?php

namespace App\Models;

use Database\Factories\DesignSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DesignSnapshot extends Model
{
    /** @use HasFactory<DesignSnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'design_id',
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

    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }
}
