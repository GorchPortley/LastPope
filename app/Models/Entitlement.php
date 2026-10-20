<?php

namespace App\Models;

use App\Enums\EntitlementSource;
use Database\Factories\EntitlementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * "This user may download this digital product." Replaces the old DesignPurchase.
 */
class Entitlement extends Model
{
    /** @use HasFactory<EntitlementFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'entitleable_type',
        'entitleable_id',
        'order_item_id',
        'source',
        'granted_at',
        'revoked_at',
    ];

    protected $casts = [
        'source' => EntitlementSource::class,
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Design or Driver. */
    public function entitleable(): MorphTo
    {
        return $this->morphTo();
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function revoke(): static
    {
        $this->forceFill(['revoked_at' => now()])->save();

        return $this;
    }
}
