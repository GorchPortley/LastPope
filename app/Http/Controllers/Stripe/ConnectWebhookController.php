<?php

namespace App\Http\Controllers\Stripe;

use App\Services\Marketplace\Webhooks\ConnectWebhookHandler;

/** POST /stripe/connect/webhook: events of connected (seller) accounts. */
class ConnectWebhookController extends StripeWebhookController
{
    public function __construct(private readonly ConnectWebhookHandler $handler)
    {
    }

    protected function secret(): ?string
    {
        return config('marketplace.stripe.connect_webhook_secret');
    }

    protected function process(object $event): string
    {
        return $this->handler->handle($event);
    }
}
