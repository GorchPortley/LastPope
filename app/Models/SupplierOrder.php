<?php

namespace App\Models;

use App\Enums\SupplierOrderStatus;
use Database\Factories\SupplierOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A purchase order (PO) to a supplier for the dropshipped lines of one paid store order (docs/PLAN.md 8.3).
 * Created by PurchaseOrderService::createForOrder() when the order is paid; admins send it, enter the supplier's
 * reference, costs and tracking. Internal: buyers never see it.
 */
class SupplierOrder extends Model
{
    /** @use HasFactory<SupplierOrderFactory> */
    use HasFactory;

    protected $fillable = [
        'number',
        'order_id',
        'seller_order_id',
        'supplier_id',
        'status',
        'shipping_address',
        'supplier_reference',
        'placed_manually',
        'items_cost_cents',
        'shipping_cost_cents',
        'dropship_fee_cents',
        'sent_at',
        'confirmed_at',
        'shipped_at',
        'cancelled_at',
        'notes',
    ];

    protected $attributes = [
        'status' => 'pending',
        'items_cost_cents' => 0,
        'dropship_fee_cents' => 0,
    ];

    protected $casts = [
        'status' => SupplierOrderStatus::class,
        'shipping_address' => 'array',
        'placed_manually' => 'boolean',
        'items_cost_cents' => 'integer',
        'shipping_cost_cents' => 'integer',
        'dropship_fee_cents' => 'integer',
        'sent_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'shipped_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $po) {
            if (blank($po->number)) {
                $po->number = static::generateNumber();
            }
        });
    }

    /** A unique, human-readable number such as PO-7KQ2M9 (no 0/O/1/I to avoid mix-ups). */
    public static function generateNumber(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $prefix = (string) config('marketplace.purchase_order_prefix', 'PO');

        do {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $number = $prefix.'-'.$suffix;
        } while (static::where('number', $number)->exists());

        return $number;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** All lines, cancelled ones included. */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierOrderItem::class)->orderBy('id');
    }

    /** The lines that are still to be bought and shipped. */
    public function activeItems(): HasMany
    {
        return $this->items()->whereNull('cancelled_at');
    }

    /** Parcels the supplier shipped for this purchase order. */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function scopeStatus(Builder $query, SupplierOrderStatus ...$statuses): Builder
    {
        return $query->whereIn('status', array_map(fn (SupplierOrderStatus $s) => $s->value, $statuses));
    }

    /** Purchase orders an admin still has to send. */
    public function scopeToSend(Builder $query): Builder
    {
        return $query->where('status', SupplierOrderStatus::PENDING->value);
    }

    /** Items plus supplier shipping plus the dropship fee: what this purchase order costs us (unknown shipping counts 0). */
    public function totalCostCents(): int
    {
        return $this->items_cost_cents + (int) $this->shipping_cost_cents + $this->dropship_fee_cents;
    }

    /** The supplier has not told us what shipping costs yet. */
    public function shippingCostPending(): bool
    {
        return $this->shipping_cost_cents === null && $this->status !== SupplierOrderStatus::CANCELLED;
    }

    public function canBeSent(): bool
    {
        return in_array($this->status, [SupplierOrderStatus::PENDING, SupplierOrderStatus::SENT], true);
    }

    /**
     * The ship-to block of the purchase order as lines of text: recipient, street, city line, country, phone. This is
     * the only place a buyer's contact detail goes to a supplier - never the buyer's e-mail address.
     *
     * @return array<int, string>
     */
    public function shipToLines(): array
    {
        $address = $this->shipping_address ?? [];

        if (blank($address)) {
            return [];
        }

        $cityLine = trim(implode(' ', array_filter([$address['postal_code'] ?? null, $address['city'] ?? null, $address['state'] ?? null])));
        $country = filled($address['country'] ?? null) ? \App\Support\Countries::name($address['country']) : null;

        return array_values(array_filter([
            $address['name'] ?? null,
            $address['line1'] ?? null,
            $address['line2'] ?? null,
            $cityLine,
            $country,
            filled($address['phone'] ?? null) ? 'Phone: '.$address['phone'] : null,
        ], fn ($line) => filled($line)));
    }
}
