<?php

namespace App\Models;

use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'purchasable_type',
        'purchasable_id',
        'quantity',
        'unit_price_cents',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price_cents' => 'integer',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** Design, Driver or Listing. */
    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }

    public function lineTotalCents(): int
    {
        return $this->unit_price_cents * $this->quantity;
    }
}
