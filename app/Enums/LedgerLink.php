<?php

namespace App\Enums;

/**
 * How a mirrored Stripe balance transaction relates to the shop (set by LedgerLinker):
 *
 *   LINKED    tied to an order (and, where Stripe says so, to one seller order)
 *   UNLINKED  money that should belong to an order but no order was found: a transfer no seller order owns, a payment
 *             that carries an order's marker but no order matches, ... (the reconciliation reports these)
 *   OTHER     not about orders: payouts, Stripe fees, Wave subscription charges on the shared account, top-ups, ...
 */
enum LedgerLink: string
{
    case LINKED = 'linked';
    case UNLINKED = 'unlinked';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::LINKED => 'Linked to an order',
            self::UNLINKED => 'Not linked',
            self::OTHER => 'Other',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::LINKED => 'success',
            self::UNLINKED => 'danger',
            self::OTHER => 'gray',
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
