<?php

namespace App\Console\Commands;

use App\Enums\SellerOrderStatus;
use App\Models\SellerOrder;
use App\Services\Marketplace\PayoutService;
use App\Services\Marketplace\ShipmentService;
use Illuminate\Console\Command;

/**
 * Hourly housekeeping for orders: shipped seller orders complete by themselves after
 * marketplace.auto_complete_days (not while a problem report is unresolved), and every payout that is
 * due is released, which includes retrying transfers that failed earlier. With the default payout trigger
 * ("shipped_delay") a physical order is paid out physical_payout_delay_days after it was shipped, by this job.
 */
class MarketplaceAutoComplete extends Command
{
    protected $signature = 'marketplace:auto-complete
        {--days= : Override marketplace.auto_complete_days}
        {--dry-run : Only list what would be completed}';

    protected $description = 'Complete shipped orders after the buyer protection period and release due (or retry failed) payouts';

    public function handle(ShipmentService $shipments, PayoutService $payouts): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : (int) config('marketplace.auto_complete_days', 14);

        if ($this->option('dry-run')) {
            $count = SellerOrder::query()
                ->where('status', SellerOrderStatus::SHIPPED->value)
                ->where('shipped_at', '<=', now()->subDays($days))
                ->whereDoesntHave('issues', fn ($issues) => $issues->unresolved())
                ->count();

            $due = SellerOrder::query()
                ->whereNull('transferred_at')
                ->where('payout_cents', '>', 0)
                ->get()
                ->filter(fn (SellerOrder $sellerOrder) => $payouts->isDue($sellerOrder))
                ->count();

            $this->info("{$count} shipped seller order(s) older than {$days} days would be completed; {$due} payout(s) are due.");

            return self::SUCCESS;
        }

        $completed = $shipments->autoCompleteDue($days);
        $paid = $payouts->releaseDue();

        $this->info("Completed {$completed} seller order(s); released {$paid} pending payout(s).");

        return self::SUCCESS;
    }
}
