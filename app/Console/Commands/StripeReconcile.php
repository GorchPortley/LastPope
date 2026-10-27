<?php

namespace App\Console\Commands;

use App\Services\Marketplace\Ledger\LedgerSync;
use App\Services\Marketplace\Ledger\Reconciler;
use Illuminate\Console\Command;

/**
 * The daily Stripe reconciliation (docs/PLAN.md 11, R6.3): brings the ledger mirror up to date, compares it with the site's
 * records, stores every difference once and mails the admins the new ones. Differences found fixed close by themselves.
 */
class StripeReconcile extends Command
{
    protected $signature = 'stripe:reconcile
        {--no-sync : Compare with the ledger as it is, without reading Stripe first}
        {--no-mail : Do not mail the admins about new differences (a later run does)}';

    protected $description = 'Compare the site\'s records with the Stripe ledger and report the differences';

    public function handle(LedgerSync $sync, Reconciler $reconciler): int
    {
        if (! $sync->enabled()) {
            $this->warn('Stripe is not configured (STRIPE_SECRET_KEY): nothing to reconcile.');

            return self::SUCCESS;
        }

        $failed = 0;

        if (! $this->option('no-sync')) {
            foreach ($sync->syncAll() as $result) {
                if ($result instanceof \Throwable) {
                    $failed++;
                    $this->error('Sync failed: '.$result->getMessage());
                }
            }
        }

        $result = $reconciler->run(notify: ! $this->option('no-mail'));

        foreach ($result->skipped as $reason) {
            $this->warn($reason);
        }

        $this->info(sprintf(
            '%d open %s (%d new), %d closed because they are fixed.',
            $result->open->count(),
            str('difference')->plural($result->open->count()),
            $result->new->count(),
            $result->autoResolved,
        ));

        foreach ($result->new as $difference) {
            $this->line('  new: '.$difference->kind->label().' - '.$difference->details);
        }

        return $failed === 0 && $result->skipped === [] ? self::SUCCESS : self::FAILURE;
    }
}
