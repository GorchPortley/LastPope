<?php

namespace App\Enums;

/** Where one order line is fulfilled from, decided at checkout and stored on order_items.fulfillment_source. */
enum FulfillmentSource: string
{
    case STOCK = 'stock';
    case SUPPLIER = 'supplier';

    public function label(): string
    {
        return match ($this) {
            self::STOCK => 'Own stock',
            self::SUPPLIER => 'Supplier',
        };
    }
}
