<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a purchase order: an order item, as the supplier knows it (their SKU, our unit cost). */
class SupplierOrderItem extends Model
{
    protected $fillable = [
        'supplier_order_id',
        'order_item_id',
        'title',
        'supplier_sku',
        'quantity',
        'unit_cost_cents',
        'cancelled_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost_cents' => 'integer',
        'cancelled_at' => 'datetime',
    ];

    public function supplierOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function lineCostCents(): int
    {
        return $this->unit_cost_cents * $this->quantity;
    }
}
