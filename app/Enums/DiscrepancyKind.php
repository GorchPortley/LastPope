<?php

namespace App\Enums;

/**
 * What the daily reconciliation (App\Services\Marketplace\Ledger\Reconciler) can find. Each case is one check; a
 * difference is stored once per kind + key and closes by itself when a later run no longer finds it.
 *
 *   CHARGE_MISSING        a paid order has no charge in the ledger
 *   CHARGE_AMOUNT         the charge is not for the order total
 *   CHARGE_UNFULFILLED    a payment for an order that is not paid on the site (a missed webhook)
 *   CHARGE_UNKNOWN_ORDER  a payment that carries an order's marker but no such order exists
 *   REFUND_TOTAL          Stripe refunded another amount than the site recorded (tax rule: see Reconciler)
 *   TRANSFER_MISSING      a transferred seller order has no transfer in the ledger
 *   TRANSFER_AMOUNT      the transfer is for another amount than the payout
 *   TRANSFER_ACCOUNT      the transfer went to another account than the seller's
 *   TRANSFER_NOT_RECEIVED the transfer is not in the seller's own ledger
 *   REVERSAL_TOTAL        the transfer reversals in Stripe differ from the site's reversed amount
 *   TRANSFER_ORPHAN       a platform transfer that no seller order owns
 *   PAYOUT_FAILED         a payout to a bank account failed
 *   DISPUTE_NO_CASE       a bank dispute with no problem report on the site
 */
enum DiscrepancyKind: string
{
    case CHARGE_MISSING = 'charge_missing';
    case CHARGE_AMOUNT = 'charge_amount';
    case CHARGE_UNFULFILLED = 'charge_unfulfilled';
    case CHARGE_UNKNOWN_ORDER = 'charge_unknown_order';
    case REFUND_TOTAL = 'refund_total';
    case TRANSFER_MISSING = 'transfer_missing';
    case TRANSFER_AMOUNT = 'transfer_amount';
    case TRANSFER_ACCOUNT = 'transfer_account';
    case TRANSFER_NOT_RECEIVED = 'transfer_not_received';
    case REVERSAL_TOTAL = 'reversal_total';
    case TRANSFER_ORPHAN = 'transfer_orphan';
    case PAYOUT_FAILED = 'payout_failed';
    case DISPUTE_NO_CASE = 'dispute_no_case';

    public function label(): string
    {
        return match ($this) {
            self::CHARGE_MISSING => 'Payment missing in Stripe',
            self::CHARGE_AMOUNT => 'Payment amount differs',
            self::CHARGE_UNFULFILLED => 'Paid in Stripe, not paid here',
            self::CHARGE_UNKNOWN_ORDER => 'Payment for an unknown order',
            self::REFUND_TOTAL => 'Refunded total differs',
            self::TRANSFER_MISSING => 'Transfer missing in Stripe',
            self::TRANSFER_AMOUNT => 'Transfer amount differs',
            self::TRANSFER_ACCOUNT => 'Transfer went to another account',
            self::TRANSFER_NOT_RECEIVED => 'Transfer not on the seller account',
            self::REVERSAL_TOTAL => 'Transfer reversals differ',
            self::TRANSFER_ORPHAN => 'Transfer without a seller order',
            self::PAYOUT_FAILED => 'Payout failed',
            self::DISPUTE_NO_CASE => 'Bank dispute without a case',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::PAYOUT_FAILED, self::DISPUTE_NO_CASE, self::CHARGE_UNFULFILLED, self::CHARGE_UNKNOWN_ORDER => 'danger',
            self::TRANSFER_ORPHAN, self::TRANSFER_ACCOUNT, self::CHARGE_MISSING, self::TRANSFER_MISSING => 'danger',
            default => 'warning',
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
