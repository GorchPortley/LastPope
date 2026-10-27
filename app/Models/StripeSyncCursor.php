<?php

namespace App\Models;

use App\Support\StripeMode;
use Illuminate\Database\Eloquent\Model;

/**
 * How far the ledger sync of one account has come (per test / live mode). synced_through is the start time of the last
 * complete pass: the next pass reads from a little before it (the overlap window). A pass that stopped at the page limit
 * is remembered in the resume_* columns and continued by the next run.
 */
class StripeSyncCursor extends Model
{
    protected $guarded = [];

    protected $casts = [
        'livemode' => 'boolean',
        'synced_through' => 'datetime',
        'resume_since' => 'datetime',
        'resume_through' => 'datetime',
        'last_started_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_error_at' => 'datetime',
        'failures' => 'integer',
    ];

    /** The cursor of an account in the current mode (created empty when it is the first sync). */
    public static function forAccount(?string $account): self
    {
        return static::query()->firstOrCreate(
            ['account_key' => StripeBalance::keyFor($account), 'livemode' => StripeMode::isLive()],
            ['account' => $account],
        );
    }

    /** A complete pass has run at least once. */
    public function hasSynced(): bool
    {
        return $this->synced_through !== null;
    }

    public function isFailing(): bool
    {
        return $this->last_error !== null;
    }
}
