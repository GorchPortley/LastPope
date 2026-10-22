<?php

namespace App\Models;

use Database\Factories\ShipmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One parcel of a seller order (docs/PLAN.md 8.3): carrier, tracking and the order items (with quantities) inside.
 * Every shipped seller order has at least one; a marketplace seller's order has exactly one, a store order one per
 * fulfilment source (the own-stock parcel, plus one per purchase order).
 */
class Shipment extends Model
{
    /** @use HasFactory<ShipmentFactory> */
    use HasFactory;

    protected $fillable = [
        'seller_order_id',
        'supplier_order_id',
        'carrier',
        'tracking_number',
        'tracking_url',
        'shipped_at',
    ];

    protected $casts = [
        'shipped_at' => 'datetime',
    ];

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    /** The purchase order this parcel came from (null for own stock and marketplace parcels). */
    public function supplierOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class);
    }

    /** @return HasMany<ShipmentItem> what is in the parcel (order item + quantity) */
    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function hasTracking(): bool
    {
        return filled($this->tracking_number);
    }

    /**
     * The parcel as the buyer's pages and e-mails show it: no supplier, no purchase order. Needs items.orderItem.
     *
     * @return array{id: int, carrier: ?string, tracking_number: ?string, tracking_url: ?string, shipped_at: ?\Illuminate\Support\Carbon, items: array<int, array{order_item_id: int, title: string, quantity: int}>}
     */
    public function summary(): array
    {
        $this->loadMissing('items.orderItem');

        return [
            'id' => $this->getKey(),
            'carrier' => $this->carrier,
            'tracking_number' => $this->tracking_number,
            'tracking_url' => $this->tracking_url,
            'shipped_at' => $this->shipped_at,
            'items' => $this->items->map(fn (ShipmentItem $item) => [
                'order_item_id' => $item->order_item_id,
                'title' => (string) $item->orderItem?->title,
                'quantity' => $item->quantity,
            ])->values()->all(),
        ];
    }
}
