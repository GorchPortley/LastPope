<?php

namespace App\Models;

use App\Enums\SellerOrderStatus;
use Database\Factories\SellerOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SellerOrder extends Model
{
    /** @use HasFactory<SellerOrderFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'seller_id',
        'status',
        'subtotal_cents',
        'shipping_cents',
        'platform_fee_cents',
        'payout_cents',
        'stripe_transfer_id',
        'transferred_at',
        'carrier',
        'tracking_number',
        'tracking_url',
        'shipped_at',
        'delivered_at',
        'refunded_cents',
        'stripe_refund_id',
        'stripe_reversal_id',
        'reversed_cents',
        'reversal_pending_cents',
        'refunded_at',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $casts = [
        'status' => SellerOrderStatus::class,
        'subtotal_cents' => 'integer',
        'shipping_cents' => 'integer',
        'platform_fee_cents' => 'integer',
        'payout_cents' => 'integer',
        'transferred_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'refunded_cents' => 'integer',
        'reversed_cents' => 'integer',
        'reversal_pending_cents' => 'integer',
        'refunded_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** The parcels of this seller order, oldest first. */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class)->orderBy('shipped_at')->orderBy('id');
    }

    /** Purchase orders to suppliers for the dropshipped lines (store orders only). */
    public function supplierOrders(): HasMany
    {
        return $this->hasMany(SupplierOrder::class);
    }

    /**
     * The parcels as the buyer sees them (carrier, tracking, items; no supplier), oldest first, each with its number
     * and the expected parcel count: "parcel 2 of 3".
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function shipmentSummaries(): \Illuminate\Support\Collection
    {
        $this->loadMissing('shipments.items.orderItem');
        $count = app(\App\Services\Marketplace\ParcelPlanner::class)->expectedParcelCount($this);

        return $this->shipments->values()->map(fn (Shipment $shipment, int $index) => $shipment->summary() + [
            'parcel' => $index + 1,
            'parcels' => $count,
        ]);
    }

    /** The Stripe ledger lines linked to this seller order (its transfer, reversals, refunds), oldest first. */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(StripeBalanceTransaction::class)->orderBy('stripe_created_at');
    }

    /** Every problem report ever opened for this seller order, newest first. */
    public function issues(): HasMany
    {
        return $this->hasMany(OrderIssue::class)->latest('id');
    }

    /** The unresolved problem report, if any (there is at most one). */
    public function activeIssue(): HasOne
    {
        return $this->hasOne(OrderIssue::class)->unresolved();
    }

    /** True while a problem report is unresolved: no payout, no automatic completion. Always asks the database. */
    public function hasUnresolvedIssue(): bool
    {
        return $this->issues()->unresolved()->exists();
    }

    public function isTransferred(): bool
    {
        return $this->transferred_at !== null;
    }

    /** Items plus shipping: what the buyer paid for this seller's part (before any tax). */
    public function grossCents(): int
    {
        return $this->subtotal_cents + $this->shipping_cents;
    }

    public function hasPhysicalItems(): bool
    {
        return $this->items->contains(fn (OrderItem $item) => ! $item->is_digital);
    }

    /** What the buyer can still be refunded for this seller's part (items + shipping minus earlier refunds). */
    public function refundableCents(): int
    {
        return max(0, $this->grossCents() - $this->refunded_cents);
    }

    /** What the seller keeps of the payout: after the transfer, what was not taken back. */
    public function netPayoutCents(): int
    {
        return $this->isTransferred() ? max(0, $this->payout_cents - $this->reversed_cents) : $this->payout_cents;
    }

    public function isRefunded(): bool
    {
        return $this->status === SellerOrderStatus::REFUNDED;
    }
}
