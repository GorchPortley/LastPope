<?php

namespace App\Models;

use App\Enums\LedgerLink;
use App\Support\StripeMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One line of Stripe's ledger (a balance transaction, txn_...) of the platform account (`account` NULL) or of a
 * connected account, mirrored by LedgerSync and linked to orders by LedgerLinker. Amounts are signed cents: money in
 * is positive, money out (transfers, refunds, payouts, fees) negative. Never edited by hand.
 */
class StripeBalanceTransaction extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'livemode' => 'boolean',
        'amount_cents' => 'integer',
        'fee_cents' => 'integer',
        'net_cents' => 'integer',
        'stripe_created_at' => 'datetime',
        'available_on' => 'datetime',
        'fee_details' => 'array',
        'source_metadata' => 'array',
        'link_status' => LedgerLink::class,
        'synced_at' => 'datetime',
    ];

    /** What the types Stripe uses most are called on our pages; anything else is its own name in plain words. */
    private const TYPE_LABELS = [
        'charge' => 'Payment',
        'payment' => 'Transfer received',
        'refund' => 'Refund',
        'payment_refund' => 'Transfer taken back',
        'payment_failure_refund' => 'Failed refund',
        'refund_failure' => 'Refund failed',
        'transfer' => 'Transfer to seller',
        'transfer_refund' => 'Transfer reversal',
        'transfer_cancel' => 'Transfer cancelled',
        'transfer_failure' => 'Transfer failed',
        'payout' => 'Payout',
        'payout_cancel' => 'Payout cancelled',
        'payout_failure' => 'Payout failed',
        'adjustment' => 'Adjustment',
        'stripe_fee' => 'Stripe fee',
        'application_fee' => 'Application fee',
        'topup' => 'Top-up',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /** Only the platform account when $account is null, else that connected account. */
    public function scopeForAccount(Builder $query, ?string $account): Builder
    {
        return $account === null ? $query->whereNull('account') : $query->where('account', $account);
    }

    /** Rows of the mode the current Stripe keys belong to (test or live). */
    public function scopeCurrentMode(Builder $query): Builder
    {
        return $query->where('livemode', StripeMode::isLive());
    }

    public function isPlatform(): bool
    {
        return $this->account === null;
    }

    /** Not yet part of the available balance (Stripe releases it on `available_on`). */
    public function isPending(): bool
    {
        return $this->available_on->isFuture();
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? Str::headline($this->type);
    }

    /** The transaction in the Stripe dashboard: the payment, transfer, payout or dispute behind it, else the balance page. */
    public function stripeUrl(): string
    {
        $live = (bool) $this->livemode;

        return match (true) {
            $this->account !== null && in_array($this->type, ['payment', 'payment_refund'], true) => StripeMode::dashboardUrl('connect/accounts/'.$this->account.'/activity', $live),
            $this->dispute_id !== null => StripeMode::dashboardUrl('disputes/'.$this->dispute_id, $live),
            $this->payout_id !== null => StripeMode::dashboardUrl('payouts/'.$this->payout_id, $live),
            $this->transfer_id !== null && $this->account === null => StripeMode::dashboardUrl('connect/transfers/'.$this->transfer_id, $live),
            $this->charge_id !== null && $this->account === null => StripeMode::dashboardUrl('payments/'.$this->charge_id, $live),
            default => StripeMode::dashboardUrl('balance/overview', $live),
        };
    }
}
