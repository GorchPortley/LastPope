<?php

namespace App\Jobs;

use App\Models\StripeBalance;
use App\Services\Marketplace\Ledger\LedgerSync;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors Stripe's books for one account (null = the platform) with LedgerSync. Queued every ten minutes for the platform
 * and every seller by the scheduler, and by the Stripe webhooks that say something changed in an account.
 *
 * Stateless and idempotent: a run reads from the cursor of the account, so it does not matter which event queued it. A job
 * that is waiting is not queued twice, but one that arrives while another runs queues another (the last run sees the
 * newest data), and runs for one account never overlap. Stripe errors retry with backoff (the error is on the cursor and
 * shown on the ledger page); a pass that stops at the page limit queues the rest.
 */
class SyncStripeLedger implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Waiting for another run to finish counts as an attempt; only real errors count towards the failure.
    public int $tries = 10;

    public int $maxExceptions = 4;

    public int $uniqueFor = 900;

    public int $timeout = 300;

    public function __construct(public ?string $account = null)
    {
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function uniqueId(): string
    {
        return StripeBalance::keyFor($this->account);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(30)->expireAfter(600)];
    }

    /**
     * Queue a sync of $account. Called from webhooks and the scheduler, which a queue problem must never break.
     *
     * @return bool whether a job was queued
     */
    public static function queueFor(?string $account): bool
    {
        try {
            self::dispatch($account);

            return true;
        } catch (\Throwable $e) {
            Log::error('Could not queue the Stripe ledger sync.', ['account' => $account, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function handle(LedgerSync $sync): void
    {
        if (! $sync->enabled()) {
            return;
        }

        try {
            $result = $sync->syncAccount($this->account, maxPages: max(1, (int) config('marketplace.ledger.job_max_pages', 20)));
        } catch (PaymentGatewayException $e) {
            // Stripe refused the request itself (a closed or foreign account): retrying changes nothing. The error is on the cursor.
            if ($e->isPermanent()) {
                Log::warning('The Stripe ledger sync was refused.', ['account' => $this->account, 'error' => $e->getMessage()]);

                return;
            }

            throw $e;
        }

        // A long history is read in several jobs: the cursor remembers where this one stopped.
        if (! $result->complete) {
            self::dispatch($this->account);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('The Stripe ledger sync failed.', ['account' => $this->account, 'error' => $exception?->getMessage()]);
    }
}
