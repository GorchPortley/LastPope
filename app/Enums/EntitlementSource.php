<?php

namespace App\Enums;

enum EntitlementSource: string
{
    case PURCHASE = 'purchase';
    case FREE = 'free';
    case OWNER = 'owner';
    case ADMIN_GRANT = 'admin_grant';

    public function label(): string
    {
        return match ($this) {
            self::PURCHASE => 'Purchased',
            self::FREE => 'Free download',
            self::OWNER => 'Owner',
            self::ADMIN_GRANT => 'Granted by admin',
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
