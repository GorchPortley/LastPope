<?php

namespace App\Enums;

enum ListingStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case SOLD_OUT = 'sold_out';
    case REMOVED = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::ACTIVE => 'Active',
            self::PAUSED => 'Paused',
            self::SOLD_OUT => 'Sold out',
            self::REMOVED => 'Removed',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::ACTIVE => 'success',
            self::PAUSED => 'warning',
            self::SOLD_OUT => 'info',
            self::REMOVED => 'danger',
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
