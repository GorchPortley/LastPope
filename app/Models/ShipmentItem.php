<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How many units of one order item travel in a shipment. */
class ShipmentItem extends Model
{
    protected $fillable = ['shipment_id', 'order_item_id', 'quantity'];

    protected $casts = ['quantity' => 'integer'];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
