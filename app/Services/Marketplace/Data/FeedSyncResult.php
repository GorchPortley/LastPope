<?php

namespace App\Services\Marketplace\Data;

use App\Models\Supplier;

/** What one supplier feed sync did (SupplierFeedSync::sync()). */
final class FeedSyncResult
{
    /**
     * @param  int  $rows  data rows in the feed that had a SKU and a quantity
     * @param  int  $matched  listings of the supplier found in the feed
     * @param  int  $updated  listings whose stock (or cost) changed
     * @param  int  $unmatched  listings of the supplier (with a SKU) that are not in the feed: left as they are
     * @param  int  $skipped  feed rows without a usable SKU or quantity
     */
    public function __construct(
        public readonly Supplier $supplier,
        public readonly bool $ok,
        public readonly int $rows = 0,
        public readonly int $matched = 0,
        public readonly int $updated = 0,
        public readonly int $unmatched = 0,
        public readonly int $skipped = 0,
        public readonly ?string $error = null,
    ) {
    }

    public function summary(): string
    {
        return $this->ok
            ? "{$this->supplier->name}: {$this->rows} feed rows, {$this->matched} listings matched, {$this->updated} updated, {$this->unmatched} not in the feed, {$this->skipped} rows skipped."
            : "{$this->supplier->name}: failed - {$this->error}";
    }
}
