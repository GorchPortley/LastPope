<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'number',
        'user_id',
        'status',
        'currency',
        'subtotal_cents',
        'shipping_cents',
        'tax_cents',
        'total_cents',
        'platform_fee_cents',
        'shipping_address',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'stripe_charge_id',
        'paid_at',
        'reserved_until',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'subtotal_cents' => 'integer',
        'shipping_cents' => 'integer',
        'tax_cents' => 'integer',
        'total_cents' => 'integer',
        'platform_fee_cents' => 'integer',
        'shipping_address' => 'array',
        'paid_at' => 'datetime',
        'reserved_until' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            if (blank($order->number)) {
                $order->number = static::generateNumber();
            }

            if (blank($order->currency)) {
                $order->currency = strtolower((string) config('marketplace.currency', 'usd'));
            }
        });
    }

    /** A unique, human-readable order number such as SDL-7KQ2M9 (no 0/O/1/I to avoid mix-ups). */
    public static function generateNumber(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $prefix = (string) config('marketplace.order_number_prefix', 'SDL');

        do {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $number = $prefix.'-'.$suffix;
        } while (static::where('number', $number)->exists());

        return $number;
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    /** The buyer. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeStatus(Builder $query, OrderStatus ...$statuses): Builder
    {
        return $query->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, $statuses));
    }

    /**
     * Pending orders whose stock hold is still running. These are the orders that reserve listing
     * stock (Listing::availableQuantity()); a cancelled, paid or expired order no longer does.
     */
    public function scopeHoldingStock(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::PENDING->value)->where('reserved_until', '>', now());
    }

    /**
     * How long checkout holds the stock, in minutes: marketplace.reservation_minutes clamped to what
     * Stripe accepts for a Checkout Session's expires_at (30 minutes to 24 hours).
     */
    public static function reservationMinutes(): int
    {
        return min(24 * 60, max(30, (int) config('marketplace.reservation_minutes', 30)));
    }

    public function isPaid(): bool
    {
        return $this->status->isPaidOrBeyond() && $this->paid_at !== null;
    }

    public function hasPhysicalItems(): bool
    {
        return $this->items()->where('is_digital', false)->exists();
    }
}
