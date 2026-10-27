<?php

namespace App\Services\Marketplace\Data;

/**
 * What one sync of an account did: how many balance transactions and payouts it read (upserted), and whether the pass
 * reached the end. An incomplete pass (page limit) is continued by the next run.
 */
final class LedgerSyncResult
{
    public function __construct(
        public readonly ?string $account,
        public readonly int $transactions,
        public readonly int $payouts,
        public readonly int $pages,
        public readonly bool $complete,
    ) {
    }

    public function label(): string
    {
        return $this->account ?? 'platform';
    }
}
