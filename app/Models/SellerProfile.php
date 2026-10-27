<?php

namespace App\Models;

use Database\Factories\SellerProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    /** The balance of the connected account as last read from Stripe, per currency (all modes: filter on livemode). */
    public function stripeBalances(): HasMany
    {
        return $this->hasMany(StripeBalance::class, 'account_key', 'stripe_account_id');
    }

    /** The newest payout of the connected account to its bank. */
    public function latestStripePayout(): HasOne
    {
        return $this->hasOne(StripePayout::class, 'account', 'stripe_account_id')->ofMany(['stripe_created_at' => 'max', 'id' => 'max'], fn ($query) => $query->where('livemode', \App\Support\StripeMode::isLive()));
    }

    /** The payout on its way to the bank that arrives first. */
    public function nextStripePayout(): HasOne
    {
        return $this->hasOne(StripePayout::class, 'account', 'stripe_account_id')->ofMany(['arrival_date' => 'min', 'id' => 'min'], fn ($query) => $query->where('livemode', \App\Support\StripeMode::isLive())->onTheWay());
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
