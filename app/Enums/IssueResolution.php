<?php

namespace App\Enums;

/** How a problem report ended. Also the type of an offer (full_refund, partial_refund or return_refund). */
enum IssueResolution: string
{
    case FULL_REFUND = 'full_refund';
    case PARTIAL_REFUND = 'partial_refund';
    case RETURN_REFUND = 'return_refund';
    case NO_ACTION = 'no_action';
    case WITHDRAWN = 'withdrawn';
    case CHARGEBACK_WON = 'chargeback_won';
    case CHARGEBACK_LOST = 'chargeback_lost';

    public function label(): string
    {
        return match ($this) {
            self::FULL_REFUND => 'Full refund',
            self::PARTIAL_REFUND => 'Partial refund',
            self::RETURN_REFUND => 'Return for a refund',
            self::NO_ACTION => 'No action',
            self::WITHDRAWN => 'Withdrawn by the buyer',
            self::CHARGEBACK_WON => 'Dispute won',
            self::CHARGEBACK_LOST => 'Dispute lost',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FULL_REFUND, self::PARTIAL_REFUND, self::RETURN_REFUND => 'warning',
            self::NO_ACTION, self::WITHDRAWN, self::CHARGEBACK_WON => 'success',
            self::CHARGEBACK_LOST => 'danger',
        };
    }

    /** The buyer got money back (the refund went through RefundService). */
    public function refunds(): bool
    {
        return in_array($this, [self::FULL_REFUND, self::PARTIAL_REFUND, self::RETURN_REFUND], true);
    }

    /** What a seller can propose. @return array<int, self> */
    public static function offerTypes(): array
    {
        return [self::FULL_REFUND, self::PARTIAL_REFUND, self::RETURN_REFUND];
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }

    /** @return array<string, string> value => label of the offers a seller can make, for selects */
    public static function offerOptions(): array
    {
        return collect(self::offerTypes())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
