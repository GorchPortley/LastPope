<?php

namespace App\Models;

use App\Contracts\Purchasable;
use App\Models\Concerns\HasStorageKey;
use App\Models\Concerns\IsDigitalPurchasable;
use Database\Factories\DriverFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class Driver extends Model implements Purchasable
{
    /** @use HasFactory<DriverFactory> */
    use HasFactory;
    use Searchable;
    use HasStorageKey;
    use IsDigitalPurchasable;

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'brand' => $this->brand,
            'model' => $this->model,
            'active' => $this->active,
            'tag' => $this->tag
        ];
    }

    protected $fillable = [
        'user_id',
        'storage_key',
        'brand',
        'model',
        'tag',
        'active',
        'category',
        'size',
        'impedance',
        'power',
        'data_price_cents',
        'msrp_cents',
        'link',
        'summary',
        'description',
        'factory_specs',
        'frequency_files',
        'impedance_files',
        'other_files',
        'card_image',
        'official',
        'forum_slug'
    ];

    protected $casts = [
        'active' => 'boolean',
        'official' => 'boolean',
        'data_price_cents' => 'integer',
        'msrp_cents' => 'integer',
        'factory_specs' => 'array',
        'frequency_files' => 'array',
        'impedance_files' => 'array',
        'other_files' => 'array',
        'card_image' => 'array'
    ];

    public static function storagePrefix(): string
    {
        return 'drivers';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The design components (DesignDriver rows) that use this driver. */
    public function designs(): HasMany
    {
        return $this->hasMany(DesignDriver::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(DriverSnapshot::class);
    }

    /** Physical units of this driver for sale in the marketplace. */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** "Brand Model" */
    public function fullName(): string
    {
        return trim($this->brand.' '.$this->model);
    }

    // --- Purchasable (the driver's measurement data pack) --------------------------------------

    public function purchasableTitle(): string
    {
        return $this->fullName().' data pack';
    }

    public function purchasablePriceCents(): int
    {
        return (int) $this->data_price_cents;
    }

    public function getSeller(): ?User
    {
        return $this->creator;
    }
}
