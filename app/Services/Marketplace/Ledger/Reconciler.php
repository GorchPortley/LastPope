<?php

namespace App\Services\Marketplace\Ledger;

use App\Enums\DiscrepancyKind;
use App\Enums\LedgerLink;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Models\Order;
use App\Models\OrderIssue;
use App\Models\SellerOrder;
use App\Models\StripeBalanceTransaction;
use App\Models\StripeDiscrepancy;
use App\Models\StripePayout;
use App\Models\StripeSyncCursor;
use App\Models\User;
use App\Notifications\StripeDifferencesFound;
use App\Services\Marketplace\Data\ReconcileResult;
use App\Support\StripeMode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Compares the site's records with the mirrored Stripe ledger (docs/PLAN.md 11, R6.3) and stores every difference once.
 *
 * Checks (each one a DiscrepancyKind):
 *  1. every paid order has its charge in the ledger, for the order total
 *  2. what Stripe refunded per charge equals what the site recorded
 *  3. every transferred seller order has its transfer (amount, destination account, received by the seller) and its reversals
 *  4. every transfer on the platform account belongs to a seller order
 *  5. payments that carry an order's marker whose order is not paid on the site (a missed webhook), or have no order at all
 *  6. payouts to a bank account that failed (and were not followed by a good one)
 *  7. bank disputes the site has no problem report for
 * Charges that are not marketplace orders (Wave's subscriptions share the account) are not part of any check.
 *
 * Refunds and tax: refunding a whole untouched order refunds the whole payment, tax included, while
 * seller_orders.refunded_cents is items + shipping only (tax cannot be split per seller, see RefundService). So Stripe's
 * refunded total for a charge matches the site when it equals the sum of refunded_cents, or, when every seller order is
 * fully refunded, that sum plus the order's tax_cents. Anything else is a difference.
 *
 * Timing: the ledger sync lags a little and webhooks need a moment. A check only looks at events older than
 * marketplace.ledger.reconcile_grace_minutes and older than the end of the last complete sync of the account concerned;
 * and only at orders from the Stripe mode of the current keys and from after the ledger's first transaction (a partial
 * backfill, or a switch from test to live keys, leaves earlier orders out). A check that could not run does not close its
 * open differences.
 *
 * A difference found again is the same row (kind + key). One no longer found is closed ("auto"). One an admin closed stays
 * closed while its numbers stay the same, and opens again when they change. New ones are mailed to the admins.
 */
class Reconciler
{
    /**
     * @param  bool  $notify  mail the admins about the new differences (the daily command does; the admin page does not, so the daily
     *                        run still tells them about what the button found)
     */
    public function run(bool $notify = true): ReconcileResult
    {
        $now = now();
        $platform = $this->cursor(null);
        $skipped = [];
        $ran = [];
        $findings = collect();

        if ($platform === null || ! $platform->hasSynced()) {
            $skipped[] = 'The Stripe ledger has not been synced yet: run php artisan stripe:ledger-sync --all first.';
        } else {
            $horizon = $now->copy()->subMinutes(max(0, (int) config('marketplace.ledger.reconcile_grace_minutes', 30)));
            $horizon = $platform->synced_through->lt($horizon) ? $platform->synced_through->copy() : $horizon;
            $since = StripeBalanceTransaction::query()->currentMode()->whereNull('account')->min('stripe_created_at');

            if ($since === null) {
                $skipped[] = 'The platform ledger has no transactions yet, so there is nothing to compare the orders with.';
            } else {
                $since = Carbon::parse($since);
                $ran = array_merge($ran, $this->checkCharges($findings, $since, $horizon));
                $ran = array_merge($ran, $this->checkRefunds($findings, $since, $horizon));
                $ran = array_merge($ran, $this->checkTransfers($findings, $since, $horizon));
                $ran = array_merge($ran, $this->checkUnpaidCharges($findings, $since, $horizon));
            }

            $ran = array_merge($ran, $this->checkOrphanTransfers($findings, $horizon), $this->checkDisputes($findings, $horizon));
        }

        // Failed payouts are in the mirror whatever the platform cursor says.
        $ran = array_merge($ran, $this->checkPayouts($findings, $now));

        return $this->store($findings, array_unique($ran, SORT_REGULAR), $skipped, $now, $notify);
    }

    // --- 1. charges ---------------------------------------------------------------------------------------------------

    /** @return array<int, DiscrepancyKind> */
    private function checkCharges(Collection $findings, CarbonInterface $since, CarbonInterface $horizon): array
    {
        $this->paidOrders($since, $horizon)->chunkById(200, function (Collection $orders) use ($findings) {
            $charges = StripeBalanceTransaction::query()->currentMode()->whereNull('account')->where('type', 'charge')
                ->whereIn('charge_id', $orders->pluck('stripe_charge_id')->all())->get()->keyBy('charge_id');

            foreach ($orders as $order) {
                $charge = $charges[$order->stripe_charge_id] ?? null;

                if ($charge === null) {
                    $findings->push($this->finding(DiscrepancyKind::CHARGE_MISSING, 'order:'.$order->getKey(), $order->currency, [
                        'order_id' => $order->getKey(), 'stripe_id' => $order->stripe_charge_id,
                        'expected' => $order->total_cents,
                        'details' => "Order {$order->number} is paid here for ".money($order->total_cents, $order->currency).", but Stripe's ledger has no payment {$order->stripe_charge_id}.",
                    ]));
                } elseif ($charge->amount_cents !== $order->total_cents || $charge->currency !== strtolower($order->currency)) {
                    $findings->push($this->finding(DiscrepancyKind::CHARGE_AMOUNT, 'order:'.$order->getKey(), $order->currency, [
                        'order_id' => $order->getKey(), 'stripe_id' => $order->stripe_charge_id,
                        'expected' => $order->total_cents, 'actual' => $charge->amount_cents,
                        'details' => "Order {$order->number} is for ".money($order->total_cents, $order->currency).' (tax included), but Stripe charged '.money($charge->amount_cents, $charge->currency).'.',
                    ]));
                }
            }
        });

        return [DiscrepancyKind::CHARGE_MISSING, DiscrepancyKind::CHARGE_AMOUNT];
    }

    // --- 2. refunds ---------------------------------------------------------------------------------------------------

    /** @return array<int, DiscrepancyKind> */
    private function checkRefunds(Collection $findings, CarbonInterface $since, CarbonInterface $horizon): array
    {
        // Orders the site recorded refunds for, and orders Stripe refunded (the site may have missed that).
        $stripeRefunded = StripeBalanceTransaction::query()->currentMode()->whereNull('account')
            ->whereIn('type', ['refund', 'refund_failure'])->whereNotNull('charge_id')
            ->selectRaw('charge_id, SUM(amount_cents) as total, MAX(stripe_created_at) as last_at')->groupBy('charge_id')->get()->keyBy('charge_id');

        $recorded = SellerOrder::query()->where('refunded_cents', '>', 0)->pluck('order_id');

        $this->paidOrders($since, $horizon)
            ->where(fn (Builder $q) => $q->whereIn('id', $recorded)->orWhereIn('stripe_charge_id', $stripeRefunded->keys()->all() ?: ['']))
            ->with('sellerOrders')
            ->chunkById(200, function (Collection $orders) use ($findings, $stripeRefunded, $horizon) {
                $inLedger = StripeBalanceTransaction::query()->currentMode()->whereNull('account')->where('type', 'charge')
                    ->whereIn('charge_id', $orders->pluck('stripe_charge_id')->all())->pluck('charge_id')->flip();

                foreach ($orders as $order) {
                    // No payment in the ledger (check 1 says so): there is nothing to compare the refunds with.
                    if (! isset($inLedger[$order->stripe_charge_id])) {
                        continue;
                    }

                    $row = $stripeRefunded[$order->stripe_charge_id] ?? null;
                    $stripe = $row === null ? 0 : -(int) $row->total;
                    $sellerOrders = $order->sellerOrders->reject(fn (SellerOrder $so) => $so->status === SellerOrderStatus::CANCELLED);
                    $recordedCents = (int) $sellerOrders->sum('refunded_cents');

                    // A refund still settling (the site's side or Stripe's is younger than the grace period): next time.
                    $latest = collect([$sellerOrders->max('refunded_at'), $row?->last_at])->filter()->map(fn ($t) => Carbon::parse($t))->max();
                    if ($latest !== null && $latest->gt($horizon)) {
                        continue;
                    }

                    // Tax: see the class comment.
                    $everythingRefunded = $sellerOrders->isNotEmpty() && $sellerOrders->every(fn (SellerOrder $so) => $so->refunded_cents >= $so->grossCents());
                    $withTax = $everythingRefunded ? $recordedCents + $order->tax_cents : $recordedCents;

                    if ($stripe === $recordedCents || $stripe === $withTax) {
                        continue;
                    }

                    $expected = $everythingRefunded && $stripe > $recordedCents ? $withTax : $recordedCents;
                    $findings->push($this->finding(DiscrepancyKind::REFUND_TOTAL, 'charge:'.$order->stripe_charge_id, $order->currency, [
                        'order_id' => $order->getKey(), 'stripe_id' => $order->stripe_charge_id,
                        'expected' => $expected, 'actual' => $stripe,
                        'details' => "Order {$order->number}: the site recorded ".money($recordedCents, $order->currency).' refunded (items and shipping'
                            .($everythingRefunded && $order->tax_cents > 0 ? '; Stripe refunds the tax too when the whole order is refunded, '.money($order->tax_cents, $order->currency) : '')
                            .'), Stripe refunded '.money($stripe, $order->currency).'.',
                    ]));
                }
            });

        return [DiscrepancyKind::REFUND_TOTAL];
    }

    // --- 3. transfers and reversals -------------------------------------------------------------------------------------

    /** @return array<int, DiscrepancyKind> */
    private function checkTransfers(Collection $findings, CarbonInterface $since, CarbonInterface $horizon): array
    {
        $received = [];

        SellerOrder::query()
            ->whereNotNull('stripe_transfer_id')
            ->whereNotNull('transferred_at')->where('transferred_at', '>=', $since)->where('transferred_at', '<=', $horizon)
            ->whereHas('order', fn (Builder $q) => $this->ofThisMode($q))
            ->with(['order', 'seller.sellerProfile'])
            ->chunkById(200, function (Collection $sellerOrders) use ($findings, $horizon, &$received) {
                $transfers = StripeBalanceTransaction::query()->currentMode()->whereNull('account')->where('type', 'transfer')
                    ->whereIn('source_id', $sellerOrders->pluck('stripe_transfer_id')->all())->get()->keyBy('source_id');
                $reversals = StripeBalanceTransaction::query()->currentMode()->whereNull('account')->where('type', 'transfer_refund')
                    ->whereIn('transfer_id', $sellerOrders->pluck('stripe_transfer_id')->all())
                    ->selectRaw('transfer_id, SUM(amount_cents) as total, MAX(stripe_created_at) as last_at')->groupBy('transfer_id')->get()->keyBy('transfer_id');
                $payments = StripeBalanceTransaction::query()->currentMode()->whereNotNull('account')->where('type', 'payment')
                    ->whereIn('transfer_id', $sellerOrders->pluck('stripe_transfer_id')->all())->get()->keyBy('transfer_id');

                foreach ($sellerOrders as $sellerOrder) {
                    $key = 'seller_order:'.$sellerOrder->getKey();
                    $currency = $sellerOrder->order->currency;
                    $ref = ['order_id' => $sellerOrder->order_id, 'seller_order_id' => $sellerOrder->getKey(), 'stripe_id' => $sellerOrder->stripe_transfer_id];
                    $label = "Order {$sellerOrder->order->number}";
                    $transfer = $transfers[$sellerOrder->stripe_transfer_id] ?? null;
                    $account = $sellerOrder->seller?->sellerProfile?->stripe_account_id;

                    if ($transfer === null) {
                        $findings->push($this->finding(DiscrepancyKind::TRANSFER_MISSING, $key, $currency, $ref + [
                            'expected' => $sellerOrder->payout_cents,
                            'details' => "{$label}: the payout of ".money($sellerOrder->payout_cents, $currency)." was recorded as transfer {$sellerOrder->stripe_transfer_id}, which Stripe's ledger does not have.",
                        ]));

                        continue;
                    }

                    if (-$transfer->amount_cents !== $sellerOrder->payout_cents) {
                        $findings->push($this->finding(DiscrepancyKind::TRANSFER_AMOUNT, $key, $currency, $ref + [
                            'expected' => $sellerOrder->payout_cents, 'actual' => -$transfer->amount_cents,
                            'details' => "{$label}: the payout is ".money($sellerOrder->payout_cents, $currency).', Stripe transferred '.money(-$transfer->amount_cents, $currency).'.',
                        ]));
                    }

                    if ($transfer->counterparty !== null && $account !== null && $transfer->counterparty !== $account) {
                        $findings->push($this->finding(DiscrepancyKind::TRANSFER_ACCOUNT, $key, $currency, $ref + [
                            'account' => $transfer->counterparty,
                            'details' => "{$label}: the transfer went to {$transfer->counterparty}, the seller's account is {$account}.",
                        ]));
                    } elseif ($account !== null) {
                        $received[] = [$sellerOrder, $key, $ref, $currency, $label, $account, $payments[$sellerOrder->stripe_transfer_id] ?? null];
                    }

                    // Reversals: what Stripe took back from the transfer against reversed_cents. Reversals still settling wait.
                    $row = $reversals[$sellerOrder->stripe_transfer_id] ?? null;
                    $reversed = $row === null ? 0 : (int) $row->total;
                    $settling = collect([$sellerOrder->refunded_at, $row?->last_at])->filter()->map(fn ($t) => Carbon::parse($t))->max()?->gt($horizon);

                    if (! $settling && $reversed !== $sellerOrder->reversed_cents) {
                        $findings->push($this->finding(DiscrepancyKind::REVERSAL_TOTAL, $key, $currency, $ref + [
                            'expected' => $sellerOrder->reversed_cents, 'actual' => $reversed,
                            'details' => "{$label}: the site took back ".money($sellerOrder->reversed_cents, $currency).' of the payout, Stripe reversed '.money($reversed, $currency)." of transfer {$sellerOrder->stripe_transfer_id}.",
                        ]));
                    }
                }
            });

        // The seller's side: the transfer must also be in the seller's own ledger, once that has been read past the transfer.
        $cursors = [];
        foreach ($received as [$sellerOrder, $key, $ref, $currency, $label, $account, $payment]) {
            $cursors[$account] ??= $this->cursor($account);
            $readTo = $cursors[$account]?->synced_through;

            if ($readTo === null || $readTo->lt($sellerOrder->transferred_at->copy()->addMinutes(5))) {
                continue;
            }

            if ($payment === null || $payment->account !== $account || $payment->amount_cents !== $sellerOrder->payout_cents) {
                $findings->push($this->finding(DiscrepancyKind::TRANSFER_NOT_RECEIVED, $key, $currency, $ref + [
                    'account' => $account, 'expected' => $sellerOrder->payout_cents, 'actual' => $payment?->account === $account ? $payment->amount_cents : null,
                    'details' => "{$label}: transfer {$sellerOrder->stripe_transfer_id} of ".money($sellerOrder->payout_cents, $currency)." is not on the seller's account {$account}"
                        .($payment !== null && $payment->account === $account ? ' for that amount (Stripe shows '.money($payment->amount_cents, $currency).').' : '.'),
                ]));
            }
        }

        return [DiscrepancyKind::TRANSFER_MISSING, DiscrepancyKind::TRANSFER_AMOUNT, DiscrepancyKind::TRANSFER_ACCOUNT, DiscrepancyKind::REVERSAL_TOTAL, DiscrepancyKind::TRANSFER_NOT_RECEIVED];
    }

    // --- 4. transfers without a seller order ----------------------------------------------------------------------------

    /** @return array<int, DiscrepancyKind> */
    private function checkOrphanTransfers(Collection $findings, CarbonInterface $horizon): array
    {
        StripeBalanceTransaction::query()->currentMode()->whereNull('account')
            ->whereIn('type', ['transfer', 'transfer_refund'])->where('link_status', LedgerLink::UNLINKED->value)
            ->where('stripe_created_at', '<=', $horizon)
            ->orderBy('stripe_created_at')
            ->get()
            ->groupBy('transfer_id')
            ->each(function (Collection $rows, $transferId) use ($findings) {
                $transfer = $rows->firstWhere('type', 'transfer') ?? $rows->first();
                $meta = $transfer->source_metadata ?? [];
                $hint = filled($meta['seller_order_id'] ?? null) ? " Its metadata names seller order {$meta['seller_order_id']}, which has no transfer recorded." : '';
                $currency = $transfer->currency;
                $sent = -(int) $rows->where('type', 'transfer')->sum('amount_cents');

                $findings->push($this->finding(DiscrepancyKind::TRANSFER_ORPHAN, 'transfer:'.($transferId ?: $transfer->id), $currency, [
                    'stripe_id' => $transferId ?: $transfer->id, 'account' => $transfer->counterparty,
                    'actual' => $sent,
                    'details' => 'Stripe transferred '.money($sent, $currency).' to '.($transfer->counterparty ?? 'an account').", but no seller order has transfer {$transferId}.".$hint,
                ]));
            });

        return [DiscrepancyKind::TRANSFER_ORPHAN];
    }

    // --- 5. payments of orders that are not paid here -----------------------------------------------------------------

    /** @return array<int, DiscrepancyKind> */
    private function checkUnpaidCharges(Collection $findings, CarbonInterface $since, CarbonInterface $horizon): array
    {
        StripeBalanceTransaction::query()->currentMode()->whereNull('account')->where('type', 'charge')
            ->where('stripe_created_at', '<=', $horizon)->where('stripe_created_at', '>=', $since)
            ->where(fn (Builder $q) => $q
                ->where('link_status', LedgerLink::UNLINKED->value)
                ->orWhere(fn (Builder $linked) => $linked->where('link_status', LedgerLink::LINKED->value)->whereHas('order', fn (Builder $orders) => $orders->whereNull('paid_at'))))
            ->with('order')
            ->orderBy('stripe_created_at')
            ->chunk(200, function (Collection $charges) use ($findings) {
                foreach ($charges as $charge) {
                    $order = $charge->order;
                    $key = 'charge:'.$charge->charge_id;

                    if ($order === null) {
                        $marker = $charge->source_metadata['order_number'] ?? $charge->source_metadata['order_id'] ?? '?';
                        $findings->push($this->finding(DiscrepancyKind::CHARGE_UNKNOWN_ORDER, $key, $charge->currency, [
                            'stripe_id' => $charge->charge_id, 'actual' => $charge->amount_cents,
                            'details' => 'Stripe took '.money($charge->amount_cents, $charge->currency)." (payment {$charge->charge_id}) for order {$marker}, which does not exist here.",
                        ]));

                        continue;
                    }

                    if (! $this->inThisMode($order)) {
                        continue;
                    }

                    $findings->push($this->finding(DiscrepancyKind::CHARGE_UNFULFILLED, $key, $charge->currency, [
                        'order_id' => $order->getKey(), 'stripe_id' => $charge->charge_id,
                        'actual' => $charge->amount_cents,
                        'details' => "Stripe took ".money($charge->amount_cents, $charge->currency)." for order {$order->number} (payment {$charge->charge_id}), but the order is {$order->status->label()} here. The payment webhook may have been missed.",
                    ]));
                }
            });

        return [DiscrepancyKind::CHARGE_UNFULFILLED, DiscrepancyKind::CHARGE_UNKNOWN_ORDER];
    }

    // --- 6. failed payouts ------------------------------------------------------------------------------------------------

    /** @return array<int, DiscrepancyKind> */
    private function checkPayouts(Collection $findings, CarbonInterface $now): array
    {
        StripePayout::query()->currentMode()->where('status', 'failed')->where('stripe_created_at', '>=', $now->copy()->subDays(120))
            ->with('seller.sellerProfile')
            ->orderBy('stripe_created_at')
            ->get()
            ->each(function (StripePayout $payout) use ($findings) {
                // Stripe pays the balance out again after a failure: a later good payout settles it.
                $later = StripePayout::query()->currentMode()->forAccount($payout->account)->where('currency', $payout->currency)
                    ->where('status', 'paid')->where('stripe_created_at', '>', $payout->stripe_created_at)->exists();

                if ($later) {
                    return;
                }

                $whose = $payout->account === null ? 'The platform' : ($payout->seller?->sellerProfile?->shop_name ?? $payout->account);
                $findings->push($this->finding(DiscrepancyKind::PAYOUT_FAILED, 'payout:'.$payout->getKey(), $payout->currency, [
                    'stripe_id' => $payout->getKey(), 'account' => $payout->account, 'actual' => $payout->amount_cents,
                    'details' => "{$whose}: the payout of ".money($payout->amount_cents, $payout->currency).' failed'
                        .($payout->failure_code ? " ({$payout->failure_code})" : '').'. '.($payout->failureExplanation() ?? ''),
                ]));
            });

        return [DiscrepancyKind::PAYOUT_FAILED];
    }

    // --- 7. disputes -------------------------------------------------------------------------------------------------------

    /** @return array<int, DiscrepancyKind> */
    private function checkDisputes(Collection $findings, CarbonInterface $horizon): array
    {
        $disputes = StripeBalanceTransaction::query()->currentMode()->whereNull('account')
            ->where('type', 'adjustment')->where('reporting_category', 'dispute')
            ->where('link_status', LedgerLink::LINKED->value)->whereNotNull('dispute_id')
            ->where('stripe_created_at', '<=', $horizon)
            ->with('order')
            ->get();

        $withCase = OrderIssue::query()->whereIn('stripe_dispute_id', $disputes->pluck('dispute_id')->all() ?: [''])->pluck('stripe_dispute_id')->all();

        foreach ($disputes->reject(fn (StripeBalanceTransaction $bt) => in_array($bt->dispute_id, $withCase, true)) as $bt) {
            $findings->push($this->finding(DiscrepancyKind::DISPUTE_NO_CASE, 'dispute:'.$bt->dispute_id, $bt->currency, [
                'order_id' => $bt->order_id, 'stripe_id' => $bt->dispute_id, 'actual' => -$bt->amount_cents,
                'details' => "Stripe took back ".money(-$bt->amount_cents, $bt->currency)." for a bank dispute ({$bt->dispute_id}) on order ".($bt->order?->number ?? '?').', but there is no problem report for it here. The dispute webhook may have been missed.',
            ]));
        }

        return [DiscrepancyKind::DISPUTE_NO_CASE];
    }

    // --- Storing ------------------------------------------------------------------------------------------------------------

    /**
     * @param  Collection<int, array<string, mixed>>  $findings
     * @param  array<int, DiscrepancyKind>  $ran  the kinds whose check ran (only those can be closed because they were not found)
     * @param  array<int, string>  $skipped
     */
    private function store(Collection $findings, array $ran, array $skipped, CarbonInterface $now, bool $notify): ReconcileResult
    {
        $live = StripeMode::isLive();
        $seen = [];
        $new = collect();

        foreach ($findings as $finding) {
            $row = StripeDiscrepancy::query()->where('kind', $finding['kind']->value)->where('key', $finding['key'])->first();
            $attributes = [
                'livemode' => $live,
                'order_id' => $finding['order_id'], 'seller_order_id' => $finding['seller_order_id'],
                'stripe_id' => $finding['stripe_id'], 'account' => $finding['account'],
                'expected_cents' => $finding['expected'], 'actual_cents' => $finding['actual'],
                'currency' => $finding['currency'], 'details' => $finding['details'], 'last_seen_at' => $now,
            ];

            if ($row === null) {
                $row = StripeDiscrepancy::query()->create($attributes + ['kind' => $finding['kind'], 'key' => $finding['key'], 'first_seen_at' => $now]);
                $new->push($row);
            } elseif ($row->isOpen()) {
                $row->forceFill($attributes)->save();
            } elseif ($row->resolution === 'manual' && $row->expected_cents === $finding['expected'] && $row->actual_cents === $finding['actual']) {
                // An admin closed it and nothing changed since: it stays closed.
                $seen[] = $row->getKey();

                continue;
            } else {
                // Fixed (or closed) and broken again, or changed after it was closed: it is a new difference.
                $closed = $row->resolved_at->format('M j, Y').($row->resolver ? ' by '.$row->resolver->name : '').($row->note ? ': '.$row->note : '');
                $row->forceFill(array_merge($attributes, [
                    'details' => $finding['details']." (Closed {$closed}, found again.)",
                    'resolved_at' => null, 'resolved_by' => null, 'resolution' => null, 'note' => null, 'notified_at' => null,
                ]))->save();
                $new->push($row);
            }

            $seen[] = $row->getKey();
        }

        // Found fixed: close what the checks that ran no longer find.
        $fixed = StripeDiscrepancy::query()->open()->where('livemode', $live)
            ->whereIn('kind', array_map(fn (DiscrepancyKind $kind) => $kind->value, $ran))
            ->whereNotIn('id', $seen ?: [0])
            ->update(['resolved_at' => $now, 'resolution' => 'auto', 'resolved_by' => null, 'note' => 'Fixed: a later check no longer finds this difference.']);

        if ($notify) {
            $this->notifyAdmins($new);
        }

        return new ReconcileResult(
            StripeDiscrepancy::query()->open()->where('livemode', $live)->with(['order', 'sellerOrder'])->orderBy('first_seen_at')->get(),
            $new,
            $fixed,
            $skipped,
        );
    }

    /** Mail the admins the differences nobody was told about yet; they count as told once the mail is queued. */
    private function notifyAdmins(Collection $new): void
    {
        $toTell = StripeDiscrepancy::query()->open()->whereNull('notified_at')->orderBy('first_seen_at')->get();

        if ($toTell->isEmpty()) {
            return;
        }

        try {
            $admins = User::role('admin')->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new StripeDifferencesFound($toTell));
                StripeDiscrepancy::query()->whereIn('id', $toTell->modelKeys())->update(['notified_at' => now()]);
            }
        } catch (\Throwable $e) {
            report($e);
            Log::error('Could not tell the admins about new Stripe differences.', ['error' => $e->getMessage()]);
        }
    }

    // --- Helpers --------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data  order_id, seller_order_id, stripe_id, account, expected, actual, details
     * @return array<string, mixed>
     */
    private function finding(DiscrepancyKind $kind, string $key, string $currency, array $data): array
    {
        return [
            'kind' => $kind, 'key' => $key, 'currency' => strtolower($currency),
            'order_id' => $data['order_id'] ?? null, 'seller_order_id' => $data['seller_order_id'] ?? null,
            'stripe_id' => $data['stripe_id'] ?? null, 'account' => $data['account'] ?? null,
            'expected' => $data['expected'] ?? null, 'actual' => $data['actual'] ?? null,
            'details' => $data['details'],
        ];
    }

    private function cursor(?string $account): ?StripeSyncCursor
    {
        return StripeSyncCursor::query()->where(['account_key' => $account ?? 'platform', 'livemode' => StripeMode::isLive()])->first();
    }

    /**
     * Paid orders from the current Stripe mode that the ledger covers: paid after its first transaction and long enough ago.
     *
     * @return Builder<Order>
     */
    private function paidOrders(CarbonInterface $since, CarbonInterface $horizon): Builder
    {
        return $this->ofThisMode(Order::query())
            ->whereNotNull('paid_at')->whereNotNull('stripe_charge_id')
            ->whereIn('status', [OrderStatus::PAID->value, OrderStatus::COMPLETED->value, OrderStatus::REFUNDED->value, OrderStatus::PARTIALLY_REFUNDED->value])
            ->where('paid_at', '>=', $since)->where('paid_at', '<=', $horizon);
    }

    /**
     * Orders the ledger can be expected to know: of the Stripe mode of the current keys (a Checkout Session id says which mode made
     * it, cs_test_ / cs_live_; orders without one, or with the fake gateway's, count for both), and not the demo data's made-up ones.
     */
    private function ofThisMode(Builder $orders): Builder
    {
        $other = StripeMode::isLive() ? 'cs_test_%' : 'cs_live_%';

        return $orders
            ->where(fn (Builder $q) => $q->whereNull('stripe_checkout_session_id')->orWhere('stripe_checkout_session_id', 'not like', $other))
            ->where(fn (Builder $q) => $q->whereNull('stripe_charge_id')->orWhere('stripe_charge_id', 'not like', 'ch_demo_%'));
    }

    private function inThisMode(Order $order): bool
    {
        return ! str_starts_with((string) $order->stripe_checkout_session_id, StripeMode::isLive() ? 'cs_test_' : 'cs_live_')
            && ! str_starts_with((string) $order->stripe_charge_id, 'ch_demo_');
    }
}
