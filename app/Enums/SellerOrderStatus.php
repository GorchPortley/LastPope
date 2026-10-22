<?php

namespace App\Enums;

enum SellerOrderStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case AWAITING_SHIPMENT = 'awaiting_shipment';
    /** Some parcels left (store orders shipped in several parcels); shipped_at stays empty until the last one. */
    case PARTIALLY_SHIPPED = 'partially_shipped';
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
            self::PARTIALLY_SHIPPED => 'Partially shipped',
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
            self::PAID, self::AWAITING_SHIPMENT, self::PARTIALLY_SHIPPED => 'warning',
            self::SHIPPED => 'info',
            self::DELIVERED, self::COMPLETED => 'success',
            self::CANCELLED => 'danger',
            self::REFUNDED => 'warning',
        };
    }

    /** Paid physical goods are still waiting to be shipped (nothing, or only some parcels, left yet). */
    public function awaitsShipment(): bool
    {
        return $this === self::AWAITING_SHIPMENT || $this === self::PARTIALLY_SHIPPED;
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
