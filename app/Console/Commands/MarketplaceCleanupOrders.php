<?php

namespace App\Console\Commands;

use App\Services\Marketplace\OrderFulfillment;
use Illuminate\Console\Command;

/**
 * Hourly tidy-up of orders that got stuck:
 *  - pending orders whose stock hold ended long ago are cancelled (normally the Stripe
 *    checkout.session.expired webhook does that; the hold itself already ends by the clock);
 *  - paid seller orders that could not be fulfilled (out of stock) and whose automatic refund failed
 *    are refunded again.
 */
class MarketplaceCleanupOrders extends Command
{
    protected $signature = 'marketplace:cleanup-orders {--hours=2 : Cancel pending orders whose reservation ended more than this many hours ago}';

    protected $description = 'Cancel abandoned pending orders and retry automatic refunds';

    public function handle(OrderFulfillment $fulfillment): int
    {
        $cancelled = $fulfillment->cancelStalePending(max(0, (int) $this->option('hours')));
        $refunded = $fulfillment->retryUnfulfillableRefunds();

        $this->info("Cancelled {$cancelled} abandoned order(s); refunded {$refunded} unfulfillable seller order(s).");

        return self::SUCCESS;
    }
}
