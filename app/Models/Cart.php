<?php

namespace App\Models;

use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    protected $fillable = ['user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /** Number of units in the cart. */
    public function itemCount(): int
    {
        return (int) $this->items()->sum('quantity');
    }

    /** Sum of the items at the prices stored on the cart (cents). Checkout re-prices. */
    public function subtotalCents(): int
    {
        return (int) $this->items->sum(fn (CartItem $item) => $item->lineTotalCents());
    }

    public function total(): int
    {
        return $this->subtotalCents();
    }
}
