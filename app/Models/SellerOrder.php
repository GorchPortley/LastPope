<?php

namespace App\Models;

use App\Enums\SellerOrderStatus;
use Database\Factories\SellerOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function isRefunded(): bool
    {
        return $this->status === SellerOrderStatus::REFUNDED;
    }
}
