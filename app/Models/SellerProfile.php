<?php

namespace App\Models;

use Database\Factories\SellerProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SellerProfile extends Model
{
    /** @use HasFactory<SellerProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'shop_name',
        'slug',
        'bio',
        'country',
        'stripe_account_id',
        'charges_enabled',
        'payouts_enabled',
        'details_submitted',
        'commission_bps',
    ];

    protected $casts = [
        'charges_enabled' => 'boolean',
        'payouts_enabled' => 'boolean',
        'details_submitted' => 'boolean',
        'commission_bps' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $profile) {
            if (blank($profile->slug)) {
                $base = Str::slug($profile->shop_name) ?: 'shop';
                $slug = $base;
                $i = 2;
                while (static::where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$i++;
                }
                $profile->slug = $slug;
            }

            if (blank($profile->country)) {
                $profile->country = strtoupper((string) config('marketplace.default_country', 'US'));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Stripe says this account can take payments and receive payouts. */
    public function canReceivePayouts(): bool
    {
        return $this->charges_enabled && $this->payouts_enabled;
    }

    /** Fee rate in basis points for this seller (override or marketplace default). */
    public function feeBps(): int
    {
        return $this->commission_bps ?? (int) config('marketplace.fee_bps', 1000);
    }

    public function scopeOnboarded(Builder $query): Builder
    {
        return $query->where('charges_enabled', true)->where('payouts_enabled', true);
    }
}
