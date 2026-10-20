<?php

namespace App\Models;

use Database\Factories\DesignDriverFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class DesignDriver extends Pivot
{
    /** @use HasFactory<DesignDriverFactory> */
    use HasFactory;

    // design_driver has an auto-increment id and is used as a normal hasMany model (Filament repeater).
    public $incrementing = true;

    protected $fillable = [
            'design_id',
            'driver_id',
            'position',
            'quantity',
            'low_frequency',
            'high_frequency',
            'air_volume',
            'description',
            'specifications',
            'frequency_files',
            'impedance_files',
            'other_files'
        ];

    protected $casts = [
        'specifications' => 'array',
        'frequency_files' => 'array',
        'impedance_files' => 'array',
        'other_files' => 'array'
    ];

    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
