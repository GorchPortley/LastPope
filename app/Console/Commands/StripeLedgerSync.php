<?php

namespace App\Console\Commands;

use App\Jobs\SyncStripeLedger;
use App\Services\Marketplace\Data\LedgerSyncResult;
use App\Services\Marketplace\Ledger\LedgerSync;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Mirror Stripe's books (balance transactions, payouts, balances) into the database (docs/PLAN.md 11, R6.1). The scheduler
 * runs `--all --queue` every ten minutes. Run it by hand once after deploying R6 to read the whole history:
 *
 *     php artisan stripe:ledger-sync --all
 *     php artisan stripe:ledger-sync --account=acct_123 --since=2025-01-01
 */
class StripeLedgerSync extends Command
{
    protected $signature = 'stripe:ledger-sync
        {--account= : One account: "platform" or a connected account id (acct_...)}
        {--all : The platform and every seller with a connected account}
        {--since= : Start a fresh pass from this date (backfill); default: continue from where the last sync ended, or read the whole history}
        {--queue : Queue the syncs instead of running them here (what the scheduler does)}';

    protected $description = 'Mirror the Stripe ledger (balance transactions, payouts, balances) of the platform and the sellers';

    public function handle(LedgerSync $sync): int
    {
        if (! $sync->enabled()) {
            $this->warn('Stripe is not configured (STRIPE_SECRET_KEY): nothing to sync.');

            return self::SUCCESS;
        }

        $accounts = match (true) {
            (bool) $this->option('all') => $sync->accounts(),
            filled($this->option('account')) => [$this->option('account') === 'platform' ? null : (string) $this->option('account')],
            default => null,
        };

        if ($accounts === null) {
            $this->error('Say which account: --account=platform, --account=acct_... or --all.');

            return self::FAILURE;
        }

        $since = null;
        if (filled($this->option('since'))) {
            try {
                $since = Carbon::parse((string) $this->option('since'))->startOfDay();
            } catch (\Throwable) {
                $this->error('--since must be a date, for example 2025-01-01.');

                return self::FAILURE;
            }
        }

        if ($this->option('queue')) {
            $queued = $this->option('all') ? $sync->queueAll() : collect($accounts)->filter(fn (?string $account) => SyncStripeLedger::queueFor($account))->count();
            $this->info("Queued {$queued} ledger ".str('sync')->plural($queued).'.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $result = $sync->syncAccount($account, $since);
                $this->line($this->describe($result));
            } catch (\Throwable $e) {
                $failed++;
                $this->error(($account ?? 'platform').': '.$e->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function describe(LedgerSyncResult $result): string
    {
        return sprintf('%s: %d transactions, %d payouts, %d %s.', $result->label(), $result->transactions, $result->payouts, $result->pages, str('page')->plural($result->pages));
    }
}
