<?php

namespace App\Http\Controllers\Stripe;

use App\Services\Marketplace\Webhooks\MarketplaceWebhookHandler;

/** POST /stripe/marketplace/webhook: payments, refunds and bank disputes on the platform account. */
class MarketplaceWebhookController extends StripeWebhookController
{
    public function __construct(private readonly MarketplaceWebhookHandler $handler)
    {
    }

    protected function secret(): ?string
    {
        return config('marketplace.stripe.marketplace_webhook_secret');
    }

    protected function process(object $event): string
    {
        return $this->handler->handle($event);
    }
}
