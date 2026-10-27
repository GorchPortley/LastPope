<?php

namespace App\Services\Marketplace\Ledger;

/**
 * The Stripe webhook events that tell us something changed in an account's books (docs/PLAN.md 11, R6.1). They carry no
 * data we rely on: the handlers only queue a ledger sync of the account concerned, and the sync reads the truth from the
 * API. Every other event stays ignored.
 */
final class LedgerEvents
{
    /** Events of the platform account (POST /stripe/marketplace/webhook): balance, payouts, transfers, refunds, disputes. */
    public static function forPlatform(string $type): bool
    {
        return $type === 'balance.available'
            || $type === 'charge.refunded'
            || str_starts_with($type, 'payout.')
            || str_starts_with($type, 'transfer.')
            || str_starts_with($type, 'charge.dispute.');
    }

    /** Events of a connected account (POST /stripe/connect/webhook, `event.account` says which): balance and payouts. */
    public static function forConnectedAccount(string $type): bool
    {
        return $type === 'balance.available' || str_starts_with($type, 'payout.');
    }
}
