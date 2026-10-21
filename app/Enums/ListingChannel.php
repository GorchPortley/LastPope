<?php

namespace App\Enums;

/**
 * Where a listing is sold. STORE listings are the platform's own new drivers (admins only, shown on
 * the driver page); MARKETPLACE listings are what users sell to each other (kits, completed speakers,
 * used gear) and are the only ones the marketplace browse shows.
 */
enum ListingChannel: string
{
    case STORE = 'store';
    case MARKETPLACE = 'marketplace';

    public function label(): string
    {
        return match ($this) {
            self::STORE => 'Store',
            self::MARKETPLACE => 'Marketplace',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::STORE => 'primary',
            self::MARKETPLACE => 'gray',
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
