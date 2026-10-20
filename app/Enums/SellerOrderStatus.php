<?php

namespace App\Enums;

enum SellerOrderStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case AWAITING_SHIPMENT = 'awaiting_shipment';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Awaiting payment',
            self::PAID => 'Paid',
            self::AWAITING_SHIPMENT => 'Awaiting shipment',
            self::SHIPPED => 'Shipped',
            self::DELIVERED => 'Delivered',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
            self::REFUNDED => 'Refunded',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::PAID, self::AWAITING_SHIPMENT => 'warning',
            self::SHIPPED => 'info',
            self::DELIVERED, self::COMPLETED => 'success',
            self::CANCELLED => 'danger',
            self::REFUNDED => 'warning',
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
