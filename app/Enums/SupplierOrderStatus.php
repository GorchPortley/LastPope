<?php

namespace App\Enums;

/**
 * Life of a purchase order (supplier order):
 *
 *   PENDING    created when the buyer's payment came in, not sent to the supplier yet
 *   SENT       e-mailed to the supplier, or placed by hand in the supplier's portal
 *   CONFIRMED  the supplier acknowledged it
 *   SHIPPED    every line is on its way (shipments exist for them)
 *   CANCELLED  every line was cancelled (refunded before it was sent)
 *   PROBLEM    needs an admin: a refund after it was sent ("cancel with supplier"), a back-order, ...
 */
enum SupplierOrderStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case CONFIRMED = 'confirmed';
    case SHIPPED = 'shipped';
    case CANCELLED = 'cancelled';
    case PROBLEM = 'problem';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'To send',
            self::SENT => 'Sent',
            self::CONFIRMED => 'Confirmed',
            self::SHIPPED => 'Shipped',
            self::CANCELLED => 'Cancelled',
            self::PROBLEM => 'Problem',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::SENT => 'info',
            self::CONFIRMED => 'primary',
            self::SHIPPED => 'success',
            self::CANCELLED => 'gray',
            self::PROBLEM => 'danger',
        };
    }

    /** Still has goods to get from the supplier. */
    public function isOpen(): bool
    {
        return in_array($this, [self::PENDING, self::SENT, self::CONFIRMED, self::PROBLEM], true);
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
