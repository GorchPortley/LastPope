<?php

namespace App\Enums;

enum ListingCondition: string
{
    case NEW = 'new';
    case OPEN_BOX = 'open_box';
    case USED_LIKE_NEW = 'used_like_new';
    case USED_GOOD = 'used_good';
    case USED_FAIR = 'used_fair';
    case FOR_PARTS = 'for_parts';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'New',
            self::OPEN_BOX => 'Open box',
            self::USED_LIKE_NEW => 'Used - like new',
            self::USED_GOOD => 'Used - good',
            self::USED_FAIR => 'Used - fair',
            self::FOR_PARTS => 'For parts / not working',
        };
    }

    /** "New" listings are new and open_box; everything else counts as used. */
    public function isNew(): bool
    {
        return in_array($this, self::newCases(), true);
    }

    public function isUsed(): bool
    {
        return ! $this->isNew();
    }

    /** @return array<int, self> */
    public static function newCases(): array
    {
        return [self::NEW, self::OPEN_BOX];
    }

    /** @return array<int, self> */
    public static function usedCases(): array
    {
        return [self::USED_LIKE_NEW, self::USED_GOOD, self::USED_FAIR, self::FOR_PARTS];
    }

    /** @return array<int, string> raw values of the "new" conditions, handy for whereIn() */
    public static function newValues(): array
    {
        return array_map(fn (self $c) => $c->value, self::newCases());
    }

    /** @return array<int, string> raw values of the "used" conditions, handy for whereIn() */
    public static function usedValues(): array
    {
        return array_map(fn (self $c) => $c->value, self::usedCases());
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
