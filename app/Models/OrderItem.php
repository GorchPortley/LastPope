<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'seller_order_id',
        'purchasable_type',
        'purchasable_id',
        'title',
        'is_digital',
        'quantity',
        'unit_price_cents',
        'shipping_cents',
        'line_total_cents',
    ];

    protected $casts = [
        'is_digital' => 'boolean',
        'quantity' => 'integer',
        'unit_price_cents' => 'integer',
        'shipping_cents' => 'integer',
        'line_total_cents' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    /** Design, Driver or Listing; null if it has been deleted since. */
    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The entitlement this (digital) line granted. */
    public function entitlement(): HasOne
    {
        return $this->hasOne(Entitlement::class);
    }
}
