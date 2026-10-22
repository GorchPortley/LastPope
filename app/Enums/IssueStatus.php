<?php

namespace App\Enums;

/**
 * Where a problem report stands.
 *
 *   OPEN            buyer and seller talk; the seller may make an offer
 *   OFFER_PENDING   the seller made an offer, the buyer has to accept or decline
 *   AWAITING_RETURN the buyer accepted a return-for-refund and sends the goods back
 *   ESCALATED       the admins decide (or a bank dispute is running)
 *   RESOLVED        finished, see IssueResolution
 */
enum IssueStatus: string
{
    case OPEN = 'open';
    case OFFER_PENDING = 'offer_pending';
    case AWAITING_RETURN = 'awaiting_return';
    case ESCALATED = 'escalated';
    case RESOLVED = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::OFFER_PENDING => 'Offer pending',
            self::AWAITING_RETURN => 'Awaiting return',
            self::ESCALATED => 'Escalated',
            self::RESOLVED => 'Resolved',
        };
    }

    /** Filament / marketplace badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::OPEN => 'warning',
            self::OFFER_PENDING, self::AWAITING_RETURN => 'info',
            self::ESCALATED => 'danger',
            self::RESOLVED => 'success',
        };
    }

    /** Not finished: a payout of the seller order is held back while an issue is in one of these states. */
    public function isUnresolved(): bool
    {
        return $this !== self::RESOLVED;
    }

    /** @return array<int, self> */
    public static function unresolved(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => $status->isUnresolved()));
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
