<?php

namespace App\Services\Marketplace\Data;

use App\Models\Order;

/** A pending order and the Stripe-hosted payment page to send the buyer to ($url). */
final class CheckoutResult
{
    public function __construct(
        public readonly Order $order,
        public readonly string $url,
        public readonly string $sessionId,
    ) {
    }
}
