<?php

namespace App\Enums;

/**
 * How a STORE listing is fulfilled (docs/PLAN.md 8):
 *
 *   STOCK                SDLabs' own inventory (listings.quantity), as for every marketplace listing
 *   DROPSHIP             always shipped by a supplier; no own quantity
 *   STOCK_THEN_DROPSHIP  own stock first; a line that own stock cannot cover is fulfilled by the supplier
 *                        (a line is never split)
 */
enum FulfillmentMode: string
{
    case STOCK = 'stock';
    case DROPSHIP = 'dropship';
    case STOCK_THEN_DROPSHIP = 'stock_then_dropship';

    public function label(): string
    {
        return match ($this) {
            self::STOCK => 'Own stock',
            self::DROPSHIP => 'Dropship (supplier ships)',
            self::STOCK_THEN_DROPSHIP => 'Own stock, then supplier',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::STOCK => 'gray',
            self::DROPSHIP => 'info',
            self::STOCK_THEN_DROPSHIP => 'warning',
        };
    }

    /** A supplier can fulfil lines of a listing in this mode (it needs a supplier). */
    public function usesSupplier(): bool
    {
        return $this !== self::STOCK;
    }

    /** Own inventory is sold in this mode. */
    public function usesStock(): bool
    {
        return $this !== self::DROPSHIP;
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
