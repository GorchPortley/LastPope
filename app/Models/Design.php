<?php

namespace App\Models;

use App\Contracts\Purchasable;
use App\Models\Concerns\HasStorageKey;
use App\Models\Concerns\IsDigitalPurchasable;
use Database\Factories\DesignFactory;
use Digikraaft\ReviewRating\Traits\HasReviewRating;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class Design extends Model implements Purchasable
{
    /** @use HasFactory<DesignFactory> */
    use HasFactory;
    use Searchable;
    use HasReviewRating;
    use HasStorageKey;
    use IsDigitalPurchasable;

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tag' => $this->tag,
            'active' => $this->active
        ];
    }

    protected $fillable = [
        'user_id',
        'storage_key',
        'name',
        'tag',
        'card_image',
        'active',
        'category',
        'price_cents',
        'build_cost_cents',
        'impedance',
        'power',
        'summary',
        'description',
        'bill_of_materials',
        'frd_files',
        'enclosure_files',
        'electronic_files',
        'design_other_files',
        'official',
        'forum_slug'
    ];

    protected $casts = [
        'active' => 'boolean',
        'official' => 'boolean',
        'price_cents' => 'integer',
        'build_cost_cents' => 'integer',
        'bill_of_materials' => 'array',
        'frd_files' => 'array',
        'enclosure_files' => 'array',
        'electronic_files' => 'array',
        'design_other_files' => 'array',
        'card_image' => 'array',
    ];

    public static function storagePrefix(): string
    {
        return 'designs';
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(DesignSnapshot::class);
    }

    public function designer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(DesignDriver::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeFree(Builder $query): Builder
    {
        return $query->where('price_cents', '<=', 0);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('price_cents', '>', 0);
    }

    // --- Purchasable ---------------------------------------------------------------------------

    public function purchasableTitle(): string
    {
        return (string) ($this->name ?: 'Untitled design');
    }

    public function purchasablePriceCents(): int
    {
        return (int) $this->price_cents;
    }

    public function getSeller(): ?User
    {
        return $this->designer;
    }
}
