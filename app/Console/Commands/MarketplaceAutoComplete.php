<?php

namespace App\Console\Commands;

use App\Enums\SellerOrderStatus;
use App\Models\SellerOrder;
use App\Services\Marketplace\PayoutService;
use App\Services\Marketplace\ShipmentService;
use Illuminate\Console\Command;

/**
 * Daily housekeeping for orders: shipped seller orders complete by themselves after
 * marketplace.auto_complete_days (the seller is paid if the payout is still pending), and any
 * payout that failed earlier is retried.
 */
class MarketplaceAutoComplete extends Command
{
    protected $signature = 'marketplace:auto-complete
        {--days= : Override marketplace.auto_complete_days}
        {--dry-run : Only list what would be completed}';

    protected $description = 'Complete shipped orders after the buyer protection period and retry pending payouts';

    public function handle(ShipmentService $shipments, PayoutService $payouts): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : (int) config('marketplace.auto_complete_days', 14);

        if ($this->option('dry-run')) {
            $count = SellerOrder::query()
                ->where('status', SellerOrderStatus::SHIPPED->value)
                ->where('shipped_at', '<=', now()->subDays($days))
                ->count();

            $this->info("{$count} shipped seller order(s) older than {$days} days would be completed.");

            return self::SUCCESS;
        }

        $completed = $shipments->autoCompleteDue($days);
        $paid = $payouts->releaseDue();

        $this->info("Completed {$completed} seller order(s); released {$paid} pending payout(s).");

        return self::SUCCESS;
    }
}
