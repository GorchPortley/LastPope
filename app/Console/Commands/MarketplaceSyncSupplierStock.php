<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Marketplace\SupplierFeedSync;
use Illuminate\Console\Command;

/**
 * Reads the stock feeds of the suppliers (a CSV over HTTP or an uploaded file) into listings.supplier_stock, hourly
 * (docs/PLAN.md 8.4). Problems are stored on the supplier (feed_last_error), nothing is deleted.
 */
class MarketplaceSyncSupplierStock extends Command
{
    protected $signature = 'marketplace:sync-supplier-stock
        {--supplier= : Only this supplier (id)}';

    protected $description = 'Update the stock of dropshipped store listings from the suppliers\' stock feeds';

    public function handle(SupplierFeedSync $sync): int
    {
        if ($this->option('supplier') !== null) {
            $supplier = Supplier::query()->find($this->option('supplier'));

            if ($supplier === null) {
                $this->error('No supplier with that id.');

                return self::FAILURE;
            }

            $results = collect([$sync->sync($supplier)]);
        } else {
            $results = $sync->syncAll();
        }

        if ($results->isEmpty()) {
            $this->info('No active supplier has a stock feed.');

            return self::SUCCESS;
        }

        foreach ($results as $result) {
            $result->ok ? $this->info($result->summary()) : $this->error($result->summary());
        }

        return $results->every(fn ($result) => $result->ok) ? self::SUCCESS : self::FAILURE;
    }
}
