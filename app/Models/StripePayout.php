<?php

namespace App\Models;

use App\Support\StripeMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payout from a Stripe balance to a bank account (po_...): the platform's own or a seller's. Mirrored by LedgerSync. */
class StripePayout extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'livemode' => 'boolean',
        'amount_cents' => 'integer',
        'automatic' => 'boolean',
        'stripe_created_at' => 'datetime',
        'arrival_date' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /** What Stripe tells us when a payout fails, in words a seller can act on. */
    private const FAILURES = [
        'account_closed' => 'The bank account is closed. Add a new bank account in your Stripe dashboard.',
        'account_frozen' => 'The bank account is frozen. Ask your bank, then add another account if needed.',
        'bank_account_restricted' => 'The bank account cannot receive payouts. Add another bank account in your Stripe dashboard.',
        'bank_ownership_changed' => 'The bank account changed owner. Update your bank details in your Stripe dashboard.',
        'could_not_process' => 'The bank could not process the payout. Stripe will try again; ask your bank if it keeps failing.',
        'debit_not_authorized' => 'The bank does not allow this payout. Check the account settings with your bank.',
        'declined' => 'The bank declined the payout. Check the account with your bank or add another one.',
        'incorrect_account_holder_name' => 'The name on the bank account does not match your Stripe details. Fix it in your Stripe dashboard.',
        'insufficient_funds' => 'The Stripe balance was too low to pay this out.',
        'invalid_account_number' => 'The bank account number is not valid. Correct it in your Stripe dashboard.',
        'invalid_currency' => 'The bank account cannot receive this currency. Add an account that can.',
        'no_account' => 'No bank account is set up for payouts. Add one in your Stripe dashboard.',
        'unsupported_card' => 'The card cannot receive payouts. Add a debit card or a bank account.',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function scopeForAccount(Builder $query, ?string $account): Builder
    {
        return $account === null ? $query->whereNull('account') : $query->where('account', $account);
    }

    public function scopeCurrentMode(Builder $query): Builder
    {
        return $query->where('livemode', StripeMode::isLive());
    }

    /** Money that has left the Stripe balance but has not reached the bank yet. */
    public function scopeOnTheWay(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'in_transit']);
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isOnTheWay(): bool
    {
        return in_array($this->status, ['pending', 'in_transit'], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Pending',
            'in_transit' => 'On the way',
            'paid' => 'Paid',
            'failed' => 'Failed',
            'canceled' => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', (string) $this->status)),
        };
    }

    /** Filament badge colour. */
    public function statusColor(): string
    {
        return match ($this->status) {
            'paid' => 'success',
            'pending', 'in_transit' => 'info',
            'failed' => 'danger',
            default => 'gray',
        };
    }

    /** Why a failed payout failed, explained; null when it did not fail. */
    public function failureExplanation(): ?string
    {
        if (! $this->isFailed()) {
            return null;
        }

        return self::FAILURES[(string) $this->failure_code]
            ?? ($this->failure_message ?: 'The payout failed. Open your Stripe dashboard for details.');
    }

    public function stripeUrl(): string
    {
        return StripeMode::dashboardUrl('payouts/'.$this->getKey(), (bool) $this->livemode);
    }
}
