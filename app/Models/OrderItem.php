<?php

namespace App\Models;

use App\Enums\FulfillmentSource;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'fulfillment_source',
        'supplier_id',
        'quantity',
        'unit_price_cents',
        'unit_cost_cents',
        'shipping_cents',
        'line_total_cents',
    ];

    protected $casts = [
        'is_digital' => 'boolean',
        'fulfillment_source' => FulfillmentSource::class,
        'quantity' => 'integer',
        'unit_price_cents' => 'integer',
        'unit_cost_cents' => 'integer',
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

    /** Design or Listing; null if it has been deleted since. */
    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The entitlement this (digital) line granted. */
    public function entitlement(): HasOne
    {
        return $this->hasOne(Entitlement::class);
    }

    /** The supplier this line is dropshipped by (internal: never show it to the buyer). */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** This line is bought from a supplier (checkout decided so). Rows from before R3 have no source and count as own stock. */
    public function isFromSupplier(): bool
    {
        return $this->fulfillment_source === FulfillmentSource::SUPPLIER;
    }

    /** The purchase order line of this item, if it is dropshipped. */
    public function supplierOrderItem(): HasOne
    {
        return $this->hasOne(SupplierOrderItem::class);
    }

    /** Where in a parcel this line travels: one row per shipment. */
    public function shipmentItems(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    /** Units of this line that are in a shipment already. */
    public function shippedQuantity(): int
    {
        return (int) $this->shipmentItems()->sum('quantity');
    }

    /** What the fulfilment group of this line is called: "stock" or "supplier:{id}" (grouping key for parcels). */
    public function fulfilmentKey(): string
    {
        return $this->isFromSupplier() ? 'supplier:'.$this->supplier_id : 'stock';
    }
}
