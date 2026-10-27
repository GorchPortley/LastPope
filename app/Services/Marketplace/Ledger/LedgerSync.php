<?php

namespace App\Services\Marketplace\Ledger;

use App\Enums\LedgerLink;
use App\Jobs\SyncStripeLedger;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\StripeBalance;
use App\Models\StripeBalanceTransaction;
use App\Models\StripePayout;
use App\Models\StripeSyncCursor;
use App\Services\Marketplace\Data\LedgerSyncResult;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGateway;
use App\Support\StripeMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mirrors Stripe's books for one account (docs/PLAN.md 11, R6.1): balance transactions, payouts and the balance of the
 * platform (null) or of a connected seller account. Read-only towards Stripe, and safe to run any time, as often as you like:
 *
 *  - Balance transactions are read newest first from a little before the end of the last complete pass (the overlap
 *    window, marketplace.ledger.overlap_minutes) and upserted by their txn_ id, so a repeat changes nothing and a transaction
 *    that showed up late is still caught. The first pass has no start and reads the whole history; `$since` starts one earlier.
 *  - A pass that stops at `$maxPages` is remembered (the resume_* columns of the cursor) and continued by the next run, so a
 *    long backfill can be spread over several queued jobs. The cursor only moves when a pass reaches the end.
 *  - Payouts change status after they are created, so the last marketplace.ledger.payout_lookback_days days are read again.
 *  - The balance is Stripe's balance object; "in transit" is the sum of the account's payouts that are on their way.
 *  - New rows are linked to orders by LedgerLinker, and the real Stripe fee of a payment is copied to orders.stripe_fee_cents.
 *
 * A failure is written to the cursor (shown on the pages) and thrown again, so the queued job retries with backoff.
 */
class LedgerSync
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly LedgerLinker $linker,
    ) {
    }

    /** Whether there is anything to sync against: Stripe keys, or the fake gateway of local development. */
    public function enabled(): bool
    {
        return filled(config('marketplace.stripe.secret_key')) || $this->gateway instanceof FakePaymentGateway;
    }

    /**
     * The accounts to mirror: the platform (null) and every seller with a connected account (not the made-up ones of the demo data).
     *
     * @return array<int, string|null>
     */
    public function accounts(): array
    {
        $sellers = SellerProfile::query()->whereNotNull('stripe_account_id')->orderBy('id')->pluck('stripe_account_id')
            ->reject(fn (string $account) => StripeMode::isDemoAccount($account))->values()->all();

        return array_merge([null], $sellers);
    }

    /**
     * @param  CarbonInterface|null  $since  start a fresh pass from here (backfill); null continues from the cursor
     * @param  int|null  $maxPages  stop after this many pages of balance transactions (null = read to the end)
     *
     * @throws PaymentGatewayException
     */
    public function syncAccount(?string $account, ?CarbonInterface $since = null, ?int $maxPages = null): LedgerSyncResult
    {
        $cursor = StripeSyncCursor::forAccount($account);
        $startedAt = now();
        $cursor->forceFill(['last_started_at' => $startedAt])->save();

        try {
            $result = $this->pass($account, $cursor, $since, $maxPages, $startedAt);
        } catch (Throwable $e) {
            $cursor->forceFill([
                'last_error' => Str::limit($e->getMessage(), 500),
                'last_error_at' => now(),
                'failures' => $cursor->failures + 1,
            ])->save();

            throw $e;
        }

        return $result;
    }

    /**
     * Sync every account one after the other. A failing account does not stop the others.
     *
     * @return array<int, LedgerSyncResult|Throwable> one entry per account
     */
    public function syncAll(?CarbonInterface $since = null, ?int $maxPages = null): array
    {
        $results = [];

        foreach ($this->accounts() as $account) {
            try {
                $results[] = $this->syncAccount($account, $since, $maxPages);
            } catch (Throwable $e) {
                report($e);
                $results[] = $e;
            }
        }

        return $results;
    }

    /**
     * Queue a sync job for the platform and every seller (what the scheduler does every ten minutes). An account that keeps
     * failing is tried only every few hours, so one closed Stripe account does not fill the failed-jobs table.
     *
     * @return int jobs queued
     */
    public function queueAll(): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $queued = 0;

        foreach ($this->accounts() as $account) {
            $cursor = StripeSyncCursor::query()->where(['account_key' => StripeBalance::keyFor($account), 'livemode' => StripeMode::isLive()])->first();

            if ($cursor !== null && $cursor->failures >= 5 && $cursor->last_error_at?->gt(now()->subHours(6))) {
                continue;
            }

            if (SyncStripeLedger::queueFor($account)) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Ask Stripe for the balance only (one call) and store it: for pages that show a balance fresher than the last full sync.
     * Null when Stripe could not be reached.
     */
    public function refreshBalance(?string $account): bool
    {
        try {
            $this->storeBalance($account, $this->gateway->retrieveBalance($account));

            return true;
        } catch (PaymentGatewayException $e) {
            report($e);

            return false;
        }
    }

    // --- One pass ---------------------------------------------------------------------------------------------------

    private function pass(?string $account, StripeSyncCursor $cursor, ?CarbonInterface $since, ?int $maxPages, CarbonInterface $startedAt): LedgerSyncResult
    {
        $overlap = max(0, (int) config('marketplace.ledger.overlap_minutes', 60));
        $resuming = $since === null && $cursor->resume_after !== null;

        if ($resuming) {
            [$from, $through, $after] = [$cursor->resume_since, $cursor->resume_through, $cursor->resume_after];
        } else {
            $through = $startedAt;
            $from = $since ?? $cursor->synced_through?->copy()->subMinutes($overlap);
            $after = null;
        }

        $pages = $transactions = 0;
        $complete = false;
        $live = StripeMode::isLive();

        while (true) {
            $page = $this->gateway->listBalanceTransactions($account, $from?->getTimestamp(), $after, 100);
            $transactions += $this->storeTransactions($account, $page['data'], $live);
            $pages++;

            if (! $page['has_more'] || $page['data'] === []) {
                $complete = true;

                break;
            }

            $after = (string) end($page['data'])['id'];

            // Remember where we are, so a crash or the page limit does not throw the pages already read away.
            $cursor->forceFill(['resume_after' => $after, 'resume_since' => $from, 'resume_through' => $through])->save();

            if ($maxPages !== null && $pages >= $maxPages) {
                break;
            }
        }

        $payouts = $this->syncPayouts($account, $from, $startedAt, $live);
        $this->storeBalance($account, $this->gateway->retrieveBalance($account));

        // Money that may have found its owner since (a transfer recorded by the site after Stripe booked it).
        $this->linker->relinkUnlinked($account);

        if ($account === null) {
            $this->fillFees(null);
        }

        $cursor->forceFill($complete
            ? ['synced_through' => $through, 'resume_after' => null, 'resume_since' => null, 'resume_through' => null, 'last_synced_at' => now(), 'last_error' => null, 'last_error_at' => null, 'failures' => 0]
            : ['last_error' => null, 'last_error_at' => null, 'failures' => 0])->save();

        return new LedgerSyncResult($account, $transactions, $payouts, $pages, $complete);
    }

    /**
     * Upsert one page of Stripe balance transactions and link them. Returns the number read.
     *
     * @param  array<int, array<string, mixed>>  $data
     */
    private function storeTransactions(?string $account, array $data, bool $live): int
    {
        if ($data === []) {
            return 0;
        }

        $now = now()->format('Y-m-d H:i:s');
        $rows = array_map(fn (array $bt) => $this->transactionRow($account, $bt, $live, $now), $data);

        // Links are not part of the update: a row that is read again keeps the links it has (LedgerLinker sets them below).
        StripeBalanceTransaction::query()->upsert($rows, ['id'], array_values(array_diff(array_keys($rows[0]), ['id', 'link_status', 'order_id', 'seller_order_id', 'seller_id', 'created_at'])));

        $ids = array_column($rows, 'id');
        $stored = StripeBalanceTransaction::query()->whereIn('id', $ids)->get();
        $this->linker->link($stored);

        // The charges of this page give their orders the real fee.
        $this->fillFees($stored->where('type', 'charge')->whereNull('account')->pluck('charge_id')->filter()->values()->all());

        return count($data);
    }

    /**
     * @param  array<string, mixed>  $bt  a Stripe balance_transaction, `source` expanded
     * @return array<string, mixed>
     */
    private function transactionRow(?string $account, array $bt, bool $live, string $now): array
    {
        $source = is_array($bt['source'] ?? null) ? $bt['source'] : ['id' => $bt['source'] ?? null];
        $ids = $this->sourceIds($source);

        return [
            'id' => (string) $bt['id'],
            'account' => $account,
            'livemode' => $live,
            'type' => (string) $bt['type'],
            'reporting_category' => $bt['reporting_category'] ?? null,
            'amount_cents' => (int) $bt['amount'],
            'fee_cents' => (int) ($bt['fee'] ?? 0),
            'net_cents' => (int) $bt['net'],
            'currency' => strtolower((string) $bt['currency']),
            'stripe_created_at' => $this->time((int) $bt['created']),
            'available_on' => $this->time((int) ($bt['available_on'] ?? $bt['created'])),
            'description' => $bt['description'] ?? null,
            'fee_details' => json_encode($bt['fee_details'] ?? []),
            'source_id' => $source['id'] ?? null,
            'source_type' => $source['object'] ?? null,
            'charge_id' => $ids['charge'],
            'payment_intent_id' => $ids['payment_intent'],
            'transfer_id' => $ids['transfer'],
            'reversal_id' => $ids['reversal'],
            'refund_id' => $ids['refund'],
            'dispute_id' => $ids['dispute'],
            'payout_id' => $ids['payout'],
            'counterparty' => $ids['counterparty'],
            'transfer_group' => $ids['transfer_group'],
            'source_metadata' => $ids['metadata'] === [] ? null : json_encode($ids['metadata']),
            'link_status' => LedgerLink::UNLINKED->value,
            'order_id' => null,
            'seller_order_id' => null,
            'seller_id' => null,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * What the object behind a balance transaction points at. Fields Stripe may send expanded or as a bare id are read both ways.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function sourceIds(array $source): array
    {
        $id = fn (mixed $value): ?string => is_array($value) ? ($value['id'] ?? null) : (filled($value) ? (string) $value : null);
        // Only the keys this site puts there (the order a payment, refund or transfer belongs to); the platform account is shared with
        // Wave's billing, whose metadata is none of our business.
        $metadata = array_intersect_key(is_array($source['metadata'] ?? null) ? $source['metadata'] : [], array_flip(['order_id', 'order_number', 'seller_order_id', 'seller_order_ids']));

        $ids = ['charge' => null, 'payment_intent' => null, 'transfer' => null, 'reversal' => null, 'refund' => null, 'dispute' => null, 'payout' => null,
            'counterparty' => null, 'transfer_group' => $source['transfer_group'] ?? null, 'metadata' => $metadata];

        switch ($source['object'] ?? null) {
            case 'charge': // a buyer's payment (ch_...) or the payment a transfer made on a seller's account (py_...)
                $ids['charge'] = $source['id'] ?? null;
                $ids['payment_intent'] = $id($source['payment_intent'] ?? null);
                $ids['transfer'] = $id($source['source_transfer'] ?? null);

                break;
            case 'refund': // re_... on the platform, pyr_... on a seller's account (refund of the py_ payment)
                $ids['refund'] = $source['id'] ?? null;
                $ids['charge'] = $id($source['charge'] ?? null);
                $ids['payment_intent'] = $id($source['payment_intent'] ?? null);
                $ids['reversal'] = $id($source['source_transfer_reversal'] ?? null);

                break;
            case 'transfer':
                $ids['transfer'] = $source['id'] ?? null;
                $ids['counterparty'] = $id($source['destination'] ?? null);
                $ids['charge'] = $id($source['source_transaction'] ?? null);

                break;
            case 'transfer_reversal':
                $ids['reversal'] = $source['id'] ?? null;
                $ids['transfer'] = $id($source['transfer'] ?? null);

                break;
            case 'payout':
                $ids['payout'] = $source['id'] ?? null;

                break;
            case 'dispute':
                $ids['dispute'] = $source['id'] ?? null;
                $ids['charge'] = $id($source['charge'] ?? null);
                $ids['payment_intent'] = $id($source['payment_intent'] ?? null);

                break;
        }

        return $ids;
    }

    /** Upsert the payouts of the lookback window (or all of them on a first or backfill pass). Returns the number read. */
    private function syncPayouts(?string $account, ?CarbonInterface $from, CarbonInterface $startedAt, bool $live): int
    {
        $lookback = $startedAt->copy()->subDays(max(1, (int) config('marketplace.ledger.payout_lookback_days', 45)));
        $since = $from === null ? null : ($from->lt($lookback) ? $from : $lookback);
        $sellerId = $account === null ? null : SellerProfile::query()->where('stripe_account_id', $account)->value('user_id');
        $now = now()->format('Y-m-d H:i:s');

        $after = null;
        $count = 0;

        do {
            $page = $this->gateway->listPayouts($account, $since?->getTimestamp(), $after, 100);

            if ($page['data'] !== []) {
                $rows = array_map(fn (array $payout) => [
                    'id' => (string) $payout['id'],
                    'account' => $account,
                    'livemode' => $live,
                    'amount_cents' => (int) $payout['amount'],
                    'currency' => strtolower((string) $payout['currency']),
                    'status' => (string) $payout['status'],
                    'type' => $payout['type'] ?? null,
                    'method' => $payout['method'] ?? null,
                    'automatic' => (bool) ($payout['automatic'] ?? true),
                    'stripe_created_at' => $this->time((int) $payout['created']),
                    'arrival_date' => isset($payout['arrival_date']) ? $this->time((int) $payout['arrival_date']) : null,
                    'description' => $payout['description'] ?? null,
                    'destination_label' => $this->destinationLabel($payout['destination'] ?? null),
                    'failure_code' => $payout['failure_code'] ?? null,
                    'failure_message' => $payout['failure_message'] ?? null,
                    'balance_transaction_id' => is_array($payout['balance_transaction'] ?? null) ? ($payout['balance_transaction']['id'] ?? null) : ($payout['balance_transaction'] ?? null),
                    'seller_id' => $sellerId,
                    'synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $page['data']);

                StripePayout::query()->upsert($rows, ['id'], array_values(array_diff(array_keys($rows[0]), ['id', 'created_at'])));
                $count += count($rows);
                $after = (string) end($page['data'])['id'];
            }
        } while ($page['has_more'] && $page['data'] !== []);

        return $count;
    }

    /** "STRIPE TEST BANK ••••6789" for a bank account, "visa ••••4242" for a card; null when Stripe sent only an id. */
    private function destinationLabel(mixed $destination): ?string
    {
        if (! is_array($destination) || blank($destination['last4'] ?? null)) {
            return null;
        }

        $name = $destination['bank_name'] ?? $destination['brand'] ?? null;

        return trim(($name ? $name.' ' : '').'••••'.$destination['last4']);
    }

    /**
     * Store the balance of an account per currency, with the payouts on their way as "in transit".
     *
     * @param  array<string, mixed>  $balance  Stripe's balance object
     */
    private function storeBalance(?string $account, array $balance): void
    {
        $live = StripeMode::isLive();
        $amounts = [];

        foreach (['available' => 'available_cents', 'pending' => 'pending_cents', 'connect_reserved' => 'connect_reserved_cents'] as $key => $column) {
            foreach ($balance[$key] ?? [] as $entry) {
                $amounts[strtolower((string) $entry['currency'])][$column] = (int) $entry['amount'];
            }
        }

        $inTransit = StripePayout::query()->forAccount($account)->where('livemode', $live)->onTheWay()->selectRaw('currency, SUM(amount_cents) as total')->groupBy('currency')->pluck('total', 'currency');

        // A currency with money on its way to the bank belongs on the page even when the balance is empty.
        foreach ($inTransit->keys() as $currency) {
            $amounts[$currency] ??= [];
        }

        foreach ($amounts as $currency => $columns) {
            StripeBalance::query()->updateOrCreate(
                ['account_key' => StripeBalance::keyFor($account), 'livemode' => $live, 'currency' => $currency],
                $columns + ['account' => $account, 'in_transit_cents' => (int) ($inTransit[$currency] ?? 0), 'refreshed_at' => now()],
            );
        }
    }

    /**
     * Copy the real fee of the payment to its order (orders.stripe_fee_cents). With the charge ids of a page it does those;
     * without, the orders paid in the last week that still have no fee (the order may have been paid after its charge was read).
     *
     * @param  array<int, string>|null  $chargeIds
     */
    private function fillFees(?array $chargeIds): void
    {
        if ($chargeIds === []) {
            return;
        }

        Order::query()
            ->whereNull('stripe_fee_cents')
            ->whereNotNull('stripe_charge_id')
            ->when(
                $chargeIds !== null,
                fn ($q) => $q->whereIn('stripe_charge_id', $chargeIds),
                fn ($q) => $q->where('paid_at', '>=', now()->subDays(7)),
            )
            ->chunkById(200, function ($orders) {
                $fees = StripeBalanceTransaction::query()
                    ->whereNull('account')
                    ->where('type', 'charge')
                    ->whereIn('charge_id', $orders->pluck('stripe_charge_id')->all())
                    ->pluck('fee_cents', 'charge_id');

                foreach ($orders as $order) {
                    if (isset($fees[$order->stripe_charge_id]) && $fees[$order->stripe_charge_id] >= 0) {
                        $order->forceFill(['stripe_fee_cents' => $fees[$order->stripe_charge_id]])->saveQuietly();
                    }
                }
            });
    }

    private function time(int $timestamp): Carbon
    {
        return Carbon::createFromTimestamp($timestamp, config('app.timezone'));
    }
}
