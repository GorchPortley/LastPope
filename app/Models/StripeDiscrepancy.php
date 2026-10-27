<?php

namespace App\Models;

use App\Enums\DiscrepancyKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A difference between the site's records and Stripe's ledger, found by the reconciliation. One row per kind + key;
 * it is open until a later run no longer finds it (resolution 'auto') or an admin closes it with a note ('manual').
 */
class StripeDiscrepancy extends Model
{
    protected $guarded = [];

    protected $casts = [
        'kind' => DiscrepancyKind::class,
        'livemode' => 'boolean',
        'expected_cents' => 'integer',
        'actual_cents' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereNotNull('resolved_at');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    /** The amounts differ: actual minus expected, null when the difference is not about an amount. */
    public function differenceCents(): ?int
    {
        return $this->expected_cents === null || $this->actual_cents === null ? null : $this->actual_cents - $this->expected_cents;
    }
}
