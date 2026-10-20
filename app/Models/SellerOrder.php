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
}
