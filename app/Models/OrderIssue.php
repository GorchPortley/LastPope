<?php

namespace App\Models;

use App\Enums\IssueReason;
use App\Enums\IssueResolution;
use App\Enums\IssueStatus;
use Database\Factories\OrderIssueFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A buyer's problem report about one seller's part of an order (docs/PLAN.md 7, R2.2). At most one
 * unresolved issue exists per seller order; while it does, that seller order is not paid out and does not
 * complete by itself. All changes go through App\Services\Marketplace\IssueService.
 */
class OrderIssue extends Model
{
    /** @use HasFactory<OrderIssueFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'seller_order_id',
        'buyer_id',
        'seller_id',
        'reason',
        'status',
        'resolution',
        'offer_type',
        'offer_cents',
        'offered_at',
        'refunded_cents',
        'recovered_cents',
        'recovered_at',
        'return_carrier',
        'return_tracking_number',
        'return_tracking_url',
        'return_shipped_at',
        'return_received_at',
        'stripe_dispute_id',
        'dispute_status',
        'dispute_reason',
        'dispute_due_at',
        'escalated_at',
        'escalated_by',
        'resolved_at',
        'resolved_by',
        'last_message_at',
    ];

    protected $attributes = [
        'status' => 'open',
        'refunded_cents' => 0,
        'recovered_cents' => 0,
    ];

    protected $casts = [
        'reason' => IssueReason::class,
        'status' => IssueStatus::class,
        'resolution' => IssueResolution::class,
        'offer_type' => IssueResolution::class,
        'offer_cents' => 'integer',
        'offered_at' => 'datetime',
        'refunded_cents' => 'integer',
        'recovered_cents' => 'integer',
        'recovered_at' => 'datetime',
        'return_shipped_at' => 'datetime',
        'return_received_at' => 'datetime',
        'dispute_due_at' => 'datetime',
        'escalated_at' => 'datetime',
        'resolved_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // The unique index on open_seller_order_id is what allows only one unresolved issue per seller order.
        static::saving(function (self $issue) {
            $issue->open_seller_order_id = $issue->status->isUnresolved() ? $issue->seller_order_id : null;
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function escalatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Oldest first. */
    public function messages(): HasMany
    {
        return $this->hasMany(OrderIssueMessage::class)->orderBy('created_at')->orderBy('id');
    }

    /** What the buyer and the seller see: everything but the admins' internal notes. */
    public function publicMessages(): HasMany
    {
        return $this->messages()->where('internal', false);
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(fn (IssueStatus $s) => $s->value, IssueStatus::unresolved()));
    }

    public function scopeStatus(Builder $query, IssueStatus ...$statuses): Builder
    {
        return $query->whereIn('status', array_map(fn (IssueStatus $s) => $s->value, $statuses));
    }

    /** Issues the given user is part of as buyer or seller. */
    public function scopeInvolving(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('buyer_id', $user->getKey())->orWhere('seller_id', $user->getKey()));
    }

    public function isUnresolved(): bool
    {
        return $this->status->isUnresolved();
    }

    public function isEscalated(): bool
    {
        return $this->status === IssueStatus::ESCALATED;
    }

    public function isDispute(): bool
    {
        return filled($this->stripe_dispute_id);
    }

    public function hasPendingOffer(): bool
    {
        return $this->status === IssueStatus::OFFER_PENDING && $this->offer_type !== null;
    }

    public function isBuyer(?User $user): bool
    {
        return $user !== null && (int) $this->buyer_id === (int) $user->getKey();
    }

    public function isSeller(?User $user): bool
    {
        return $user !== null && (int) $this->seller_id === (int) $user->getKey();
    }

    /** When buyer and seller have had escalation_days to agree (the earliest either side may escalate). */
    public function escalatableAt(): Carbon
    {
        return $this->created_at->copy()->addDays(max(0, (int) config('marketplace.issue_escalation_days', 3)));
    }

    /** Not escalated yet and the negotiation time is over. */
    public function escalationOpen(): bool
    {
        return in_array($this->status, [IssueStatus::OPEN, IssueStatus::OFFER_PENDING, IssueStatus::AWAITING_RETURN], true)
            && $this->escalatableAt()->lte(now());
    }

    /** What can still be refunded on the seller order (items + shipping minus what was refunded already). */
    public function refundableCents(): int
    {
        $sellerOrder = $this->sellerOrder;

        return $sellerOrder === null ? 0 : max(0, $sellerOrder->grossCents() - $sellerOrder->refunded_cents);
    }

    /** The pending (or last) offer in words: "a full refund", "a partial refund of $20.00"... */
    public function offerLabel(): ?string
    {
        if ($this->offer_type === null) {
            return null;
        }

        $currency = $this->order?->currency;

        return match ($this->offer_type) {
            IssueResolution::FULL_REFUND => 'a full refund',
            IssueResolution::PARTIAL_REFUND => 'a partial refund of '.money((int) $this->offer_cents, $currency),
            IssueResolution::RETURN_REFUND => 'a full refund once the goods are sent back',
            default => null,
        };
    }

    /** The shared issue page (buyer, seller and admins). */
    public function url(): string
    {
        return route('dashboard.issues.show', ['orderIssue' => $this->getKey()]);
    }
}
