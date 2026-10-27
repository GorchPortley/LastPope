<?php

namespace App\Services\Marketplace\Data;

use App\Models\StripeDiscrepancy;
use Illuminate\Support\Collection;

/**
 * What one reconciliation run found.
 *
 *   open          every difference that is open after the run
 *   new           the ones opened (or opened again because they changed) by this run: what the admins are mailed about
 *   autoResolved  how many open differences were found fixed and closed
 *   skipped       checks that could not run, with the reason (e.g. the ledger was never synced)
 */
final class ReconcileResult
{
    /**
     * @param  Collection<int, StripeDiscrepancy>  $open
     * @param  Collection<int, StripeDiscrepancy>  $new
     * @param  array<int, string>  $skipped
     */
    public function __construct(
        public readonly Collection $open,
        public readonly Collection $new,
        public readonly int $autoResolved,
        public readonly array $skipped = [],
    ) {
    }
}
