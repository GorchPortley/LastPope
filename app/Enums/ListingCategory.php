<?php

namespace App\Enums;

/**
 * What a listing is. DRIVER belongs to the store channel (a new catalogue driver sold by SDLabs);
 * KIT, COMPLETED_SPEAKER and USED_GEAR belong to the marketplace channel.
 */
enum ListingCategory: string
{
    case DRIVER = 'driver';
    case KIT = 'kit';
    case COMPLETED_SPEAKER = 'completed_speaker';
    case USED_GEAR = 'used_gear';

    public function label(): string
    {
        return match ($this) {
            self::DRIVER => 'Driver',
            self::KIT => 'Kit',
            self::COMPLETED_SPEAKER => 'Completed speaker',
            self::USED_GEAR => 'Used gear',
        };
    }

    /** Plural form for headings and filters. */
    public function pluralLabel(): string
    {
        return match ($this) {
            self::DRIVER => 'Drivers',
            self::KIT => 'Kits',
            self::COMPLETED_SPEAKER => 'Completed speakers',
            self::USED_GEAR => 'Used gear',
        };
    }

    /** The channel this category can be sold in. */
    public function channel(): ListingChannel
    {
        return $this === self::DRIVER ? ListingChannel::STORE : ListingChannel::MARKETPLACE;
    }

    /** @return array<int, self> the categories users can pick in the marketplace */
    public static function marketplaceCases(): array
    {
        return [self::KIT, self::COMPLETED_SPEAKER, self::USED_GEAR];
    }

    /** @return array<int, string> raw values of the marketplace categories */
    public static function marketplaceValues(): array
    {
        return array_map(fn (self $c) => $c->value, self::marketplaceCases());
    }

    /** @return array<string, string> value => label, for selects (marketplace categories only) */
    public static function marketplaceOptions(): array
    {
        return collect(self::marketplaceCases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
