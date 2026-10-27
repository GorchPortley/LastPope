<?php

namespace App\Models;

use App\Support\StripeMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The latest Stripe balance of an account in one currency. available / pending come from Stripe's balance object;
 * in_transit is the sum of the account's payouts that are on their way to the bank (LedgerSync works it out).
 */
class StripeBalance extends Model
{
    protected $guarded = [];

    protected $casts = [
        'livemode' => 'boolean',
        'available_cents' => 'integer',
        'pending_cents' => 'integer',
        'in_transit_cents' => 'integer',
        'connect_reserved_cents' => 'integer',
        'refreshed_at' => 'datetime',
    ];

    /** The key of an account in account_key columns: 'platform' or acct_.... */
    public static function keyFor(?string $account): string
    {
        return $account ?? 'platform';
    }

    public function scopeForAccount(Builder $query, ?string $account): Builder
    {
        return $query->where('account_key', self::keyFor($account));
    }

    public function scopeCurrentMode(Builder $query): Builder
    {
        return $query->where('livemode', StripeMode::isLive());
    }
}
