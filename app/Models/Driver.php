<?php

namespace App\Models;

use App\Enums\ListingStatus;
use App\Models\Concerns\HasStorageKey;
use Database\Factories\DriverFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Scout\Searchable;

class Driver extends Model
{
    /** @use HasFactory<DriverFactory> */
    use HasFactory;
    use Searchable;
    use HasStorageKey;

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

    /** Every listing of this driver: the store listing and the marketplace listings of used units. */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /**
     * SDLabs' active store listing as a relation, so lists of drivers can eager-load it
     * (`Driver::with('activeStoreListing')`) instead of asking one query per driver.
     */
    public function activeStoreListing(): HasOne
    {
        return $this->hasOne(Listing::class)->ofMany(['id' => 'max'], fn (Builder $listings) => $listings->store()->active());
    }

    /**
     * SDLabs' active store listing of this new driver, or null. At most one store listing per driver
     * is active at a time (Listing::channelRuleViolations()). Whether it is in stock is up to the
     * caller: check isAvailableForPurchase() or use the buy box. Uses the eager-loaded relation when
     * there is one.
     */
    public function storeListing(): ?Listing
    {
        if ($this->relationLoaded('activeStoreListing')) {
            return $this->getRelation('activeStoreListing');
        }

        return $this->listings()->store()->active()->orderByDesc('id')->first();
    }

    /**
     * The store listing the driver page talks about: the active one, or else the newest one that sold
     * out (a sold-out listing leaves the active state, so storeListing() is null then). Draft, paused and
     * removed store listings do not count: those are off sale on purpose, not out of stock.
     */
    public function latestStoreListing(): ?Listing
    {
        return $this->listings()->store()
            ->whereIn('status', [ListingStatus::ACTIVE->value, ListingStatus::SOLD_OUT->value])
            ->orderByDesc('id')
            ->first();
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
}
