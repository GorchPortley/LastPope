<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';
    case PARTIALLY_REFUNDED = 'partially_refunded';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Awaiting payment',
            self::PAID => 'Paid',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
            self::REFUNDED => 'Refunded',
            self::PARTIALLY_REFUNDED => 'Partially refunded',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::PAID => 'info',
            self::COMPLETED => 'success',
            self::CANCELLED => 'danger',
            self::REFUNDED, self::PARTIALLY_REFUNDED => 'warning',
        };
    }

    /** True once the buyer's money has been captured (even if later refunded). */
    public function isPaidOrBeyond(): bool
    {
        return $this !== self::PENDING && $this !== self::CANCELLED;
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
