<?php

namespace App\Services\Marketplace\Webhooks;

use App\Jobs\SyncStripeLedger;
use App\Models\SellerProfile;
use App\Services\Marketplace\ConnectOnboardingService;
use App\Services\Marketplace\Ledger\LedgerEvents;

/**
 * Events of connected accounts (POST /stripe/connect/webhook):
 *
 *   account.updated   copy charges_enabled / payouts_enabled / details_submitted to the seller profile
 *   payout.*, balance.available
 *                     queue a sync of that seller's Stripe ledger (SyncStripeLedger, docs/PLAN.md 11); `event.account` says whose
 *
 * Unknown accounts and events are ignored.
 */
class ConnectWebhookHandler
{
    public function __construct(private readonly ConnectOnboardingService $connect)
    {
    }

    /** @return MarketplaceWebhookHandler::HANDLED|MarketplaceWebhookHandler::IGNORED */
    public function handle(object $event): string
    {
        if (LedgerEvents::forConnectedAccount($event->type)) {
            return $this->ledgerChanged($event);
        }

        if ($event->type !== 'account.updated') {
            return MarketplaceWebhookHandler::IGNORED;
        }

        $account = $event->data->object;
        $accountId = $account->id ?? ($event->account ?? null);

        $profile = filled($accountId) ? SellerProfile::query()->where('stripe_account_id', $accountId)->first() : null;
        if ($profile === null) {
            return MarketplaceWebhookHandler::IGNORED;
        }

        $this->connect->applyStatus($profile, [
            'charges_enabled' => (bool) ($account->charges_enabled ?? false),
            'payouts_enabled' => (bool) ($account->payouts_enabled ?? false),
            'details_submitted' => (bool) ($account->details_submitted ?? false),
        ]);

        return MarketplaceWebhookHandler::HANDLED;
    }

    /** A payout or balance event of a connected account: sync the books of that seller. */
    private function ledgerChanged(object $event): string
    {
        $accountId = $event->account ?? null;

        if (blank($accountId) || ! SellerProfile::query()->where('stripe_account_id', $accountId)->exists()) {
            return MarketplaceWebhookHandler::IGNORED;
        }

        SyncStripeLedger::queueFor((string) $accountId);

        return MarketplaceWebhookHandler::HANDLED;
    }
}
