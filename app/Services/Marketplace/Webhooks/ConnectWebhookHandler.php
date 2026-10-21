<?php

namespace App\Services\Marketplace\Webhooks;

use App\Models\SellerProfile;
use App\Services\Marketplace\ConnectOnboardingService;

/**
 * Events of connected accounts (POST /stripe/connect/webhook):
 *
 *   account.updated   copy charges_enabled / payouts_enabled / details_submitted to the seller profile
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
}
