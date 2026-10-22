<?php

namespace App\Services\Marketplace;

use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Models\User;
use App\Notifications\OrderRefunded;
use App\Services\Marketplace\Data\RefundResult;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Admin refunds (docs/PLAN.md 3.8 step 6).
 *
 * A seller order is refunded for items + shipping (tax cannot be split per seller, so a partial
 * refund leaves any Stripe Tax on the payment; refunding a whole untouched order refunds the full
 * payment including tax). Refunding also takes the payout back from the seller when a transfer was
 * made, revokes the buyer's entitlements for the refunded digital items, marks the seller orders
 * refunded and recomputes the order (refunded, or partially_refunded when other sellers' parts stay).
 * Stock is not restored: the seller relists if the goods are back.
 *
 * Partial refunds (a problem report settled with part of the money back, docs/PLAN.md 7): refunded_cents of the
 * seller order grows and its status stays. Before the payout the payout shrinks by the refunded amount (the platform
 * fee is kept); after the payout that amount of the transfer is reversed (reversed_cents). A full refund (what is left
 * of the seller order) cancels a pending payout, waiving the fee, or reverses what is left of the transfer.
 *
 * refundOrder(), refundSellerOrder() and refundSellerOrderPartially() authorize through the `refund` policy ability
 * (admins only). refundSellerOrderAutomatically() and refundForIssue() are the system paths (no authorization: the
 * caller, e.g. IssueService, already did that). Gateway failures propagate as PaymentGatewayException before
 * anything is changed locally.
 */
class RefundService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly OrderStatusSynchronizer $statuses,
    ) {
    }

    /** Refund everything that is not refunded yet on the order. */
    public function refundOrder(User $actor, Order $order, ?string $reason = null): RefundResult
    {
        Gate::forUser($actor)->authorize('refund', $order);

        return $this->process($order, null, $reason, $actor);
    }

    /** Refund one seller's part of an order. */
    public function refundSellerOrder(User $actor, SellerOrder $sellerOrder, ?string $reason = null): RefundResult
    {
        Gate::forUser($actor)->authorize('refund', $sellerOrder);

        return $this->process($sellerOrder->order, $sellerOrder->getKey(), $reason, $actor);
    }

    /**
     * Refund a seller order on the system's behalf, e.g. when the goods ran out between checkout and
     * payment (OrderFulfillment). There is no acting user, so no authorization: never call this from a
     * request that a user controls. $reason is shown to the buyer.
     */
    public function refundSellerOrderAutomatically(SellerOrder $sellerOrder, string $reason): RefundResult
    {
        return $this->process($sellerOrder->order, $sellerOrder->getKey(), $reason, null, $reason);
    }

    /** Refund part of one seller order (items + shipping, never more than what is left of it). Admin only. */
    public function refundSellerOrderPartially(User $actor, SellerOrder $sellerOrder, int $amountCents, ?string $reason = null): RefundResult
    {
        Gate::forUser($actor)->authorize('refund', $sellerOrder);

        return $this->process($sellerOrder->order, $sellerOrder->getKey(), $reason, $actor, partialCents: $amountCents);
    }

    /**
     * Refund a seller order because a problem report was settled: $amountCents of it, or everything that is left
     * when null (or when the amount covers what is left). No authorization, no refund mail to the buyer: IssueService
     * checked who may do this and tells the buyer itself. $actor is only recorded in the Stripe metadata.
     */
    public function refundForIssue(SellerOrder $sellerOrder, ?int $amountCents, string $reason, ?User $actor = null): RefundResult
    {
        return $this->process($sellerOrder->order, $sellerOrder->getKey(), $reason, $actor, partialCents: $amountCents, notify: false);
    }

    /**
     * Take back what the seller was paid for a seller order whose buyer won a bank dispute (the platform bears the
     * dispute, see IssueService::recoverFromSeller()). A payout that was not made yet is cancelled instead.
     * No authorization. Returns the cents recovered or cancelled.
     *
     * @throws PaymentGatewayException  when Stripe refuses the reversal (nothing is changed then)
     */
    public function recoverPayout(SellerOrder $sellerOrder): int
    {
        return (int) DB::transaction(function () use ($sellerOrder) {
            /** @var SellerOrder $locked */
            $locked = SellerOrder::query()->whereKey($sellerOrder->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isTransferred()) {
                $cancelled = $locked->payout_cents;
                $locked->forceFill(['payout_cents' => 0])->save();

                return $cancelled;
            }

            $amount = max(0, $locked->payout_cents - $locked->reversed_cents);
            if ($amount === 0 || blank($locked->stripe_transfer_id)) {
                return 0;
            }

            $id = $this->gateway->reverseTransfer($locked->stripe_transfer_id, $amount, [
                'order_id' => $locked->order_id,
                'seller_order_id' => $locked->getKey(),
                'reason' => 'dispute_lost',
                'idempotency_key' => $this->reversalKey($locked, $amount),
            ]);

            $locked->forceFill([
                'stripe_reversal_id' => $id,
                'reversed_cents' => $locked->reversed_cents + $amount,
            ])->save();

            return $amount;
        });
    }

    /** Try again to take the payout back from a seller after RefundResult::$reversalFailed. */
    public function retryTransferReversal(User $actor, SellerOrder $sellerOrder): bool
    {
        Gate::forUser($actor)->authorize('refund', $sellerOrder);

        // Pending amount from a partial or full refund, or (rows from before partial refunds existed) the whole payout.
        $amount = $sellerOrder->reversal_pending_cents;
        if ($amount <= 0 && $sellerOrder->isRefunded() && ! $sellerOrder->stripe_reversal_id) {
            $amount = max(0, $sellerOrder->payout_cents - $sellerOrder->reversed_cents);
        }

        if ($amount <= 0 || ! $sellerOrder->isTransferred() || blank($sellerOrder->stripe_transfer_id)) {
            return false;
        }

        $id = $this->gateway->reverseTransfer($sellerOrder->stripe_transfer_id, $amount, [
            'seller_order_id' => $sellerOrder->getKey(),
            'idempotency_key' => $this->reversalKey($sellerOrder, $amount),
        ]);

        $sellerOrder->forceFill([
            'stripe_reversal_id' => $id,
            'reversed_cents' => $sellerOrder->reversed_cents + $amount,
            'reversal_pending_cents' => 0,
        ])->save();

        return true;
    }

    /**
     * Webhook `charge.refunded`: bring our records in line with Stripe. A refund made by this service
     * is already recorded and changes nothing. A full refund made in the Stripe dashboard refunds every
     * remaining seller order here; a partial one is attributed to a seller order only when the amount
     * matches exactly, otherwise the order is just flagged partially_refunded for the admin to sort out.
     */
    public function syncFromCharge(Order $order, int $chargeAmountCents, int $amountRefundedCents): void
    {
        if ($amountRefundedCents <= 0) {
            return;
        }

        $notify = null;

        DB::transaction(function () use ($order, $chargeAmountCents, $amountRefundedCents, &$notify) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $sellerOrders = $locked->sellerOrders()->with(['items', 'seller'])->get();

            if ($locked->paid_at === null) {
                return;
            }

            $recorded = (int) $sellerOrders->sum('refunded_cents');
            if ($amountRefundedCents <= $recorded) {
                return;
            }

            $delta = $amountRefundedCents - $recorded;
            $open = $sellerOrders->reject(fn (SellerOrder $so) => in_array($so->status, [SellerOrderStatus::REFUNDED, SellerOrderStatus::CANCELLED], true));
            $refunded = [];

            if ($amountRefundedCents >= $chargeAmountCents) {
                $refunded = $open->all();
            } elseif ($match = $open->first(fn (SellerOrder $so) => $so->grossCents() === $delta)) {
                $refunded = [$match];
            } else {
                Log::warning('Partial refund in Stripe could not be matched to a seller order.', ['order' => $locked->number, 'amount_refunded' => $amountRefundedCents]);
                if ($locked->status !== OrderStatus::REFUNDED) {
                    $locked->forceFill(['status' => OrderStatus::PARTIALLY_REFUNDED])->save();
                }
            }

            foreach ($refunded as $sellerOrder) {
                $this->applyRefund($sellerOrder, null, $sellerOrder->refundableCents());
            }

            $this->statuses->sync($locked);
            $notify = [$locked, $delta];
        });

        if ($notify !== null) {
            $this->notifyBuyer($notify[0], $notify[1], null);
        }
    }

    /**
     * @param  int|null  $onlySellerOrderId  null = every open seller order of the order
     * @param  int|null  $partialCents  with a single seller order: refund only this much of it (never more than is left);
     *                                  null = everything that is left
     * @param  bool  $notify  send the buyer the refund mail
     */
    private function process(Order $order, ?int $onlySellerOrderId, ?string $reason, ?User $actor, ?string $buyerNote = null, ?int $partialCents = null, bool $notify = true): RefundResult
    {
        $result = DB::transaction(function () use ($order, $onlySellerOrderId, $reason, $actor, $partialCents) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->paid_at === null || blank($locked->stripe_payment_intent_id)) {
                throw new InvalidOrderStateException('This order was never paid, so there is nothing to refund.');
            }

            $all = $locked->sellerOrders()->with(['items', 'seller'])->get();
            $open = $all->reject(fn (SellerOrder $so) => in_array($so->status, [SellerOrderStatus::REFUNDED, SellerOrderStatus::CANCELLED], true));
            $targets = $onlySellerOrderId === null ? $open : $open->where('id', $onlySellerOrderId);

            if ($targets->isEmpty()) {
                throw new InvalidOrderStateException('Nothing left to refund on this order.');
            }

            // What each target gives back: its whole remainder, or the requested part of a single seller order.
            $amounts = $targets->mapWithKeys(fn (SellerOrder $so) => [$so->getKey() => $so->refundableCents()]);
            $partial = false;

            if ($partialCents !== null) {
                if ($targets->count() !== 1) {
                    throw new InvalidOrderStateException('A partial refund applies to one seller order.');
                }

                if ($partialCents < 1) {
                    throw new InvalidOrderStateException('The refund amount must be at least one cent.');
                }

                $only = $targets->first();
                if ($partialCents < $only->refundableCents()) {
                    $amounts[$only->getKey()] = $partialCents;
                    $partial = true;
                }
            }

            $gross = (int) $amounts->sum();
            $untouched = $all->every(fn (SellerOrder $so) => $so->refunded_cents === 0);
            $wholeUntouchedOrder = ! $partial && $untouched && $targets->count() === $open->count() && $open->count() === $all->count();

            // Refunding the entire payment (tax included) is a full refund; otherwise items + shipping of the targets.
            $amount = $wholeUntouchedOrder ? null : $gross;
            $reported = $wholeUntouchedOrder ? $locked->total_cents : $gross;

            $key = $onlySellerOrderId !== null
                ? 'refund-seller-order-'.$onlySellerOrderId.($targets->first()->refunded_cents > 0 || $partial ? '-'.$targets->first()->refunded_cents.'-'.$gross : '')
                : 'refund-order-'.$locked->getKey().'-'.($amount ?? 'full').($untouched ? '' : '-'.(int) $all->sum('refunded_cents'));

            $refundId = $this->gateway->refund($locked->stripe_payment_intent_id, $amount, [
                'order_id' => $locked->getKey(),
                'order_number' => $locked->number,
                'seller_order_ids' => $targets->pluck('id')->implode(','),
                'reason' => $reason,
                'refunded_by' => $actor?->getKey() ?? 'system',
                'idempotency_key' => $key,
            ]);

            $failed = [];
            foreach ($targets as $sellerOrder) {
                if (! $this->applyRefund($sellerOrder, $refundId, (int) $amounts[$sellerOrder->getKey()])) {
                    $failed[] = $sellerOrder;
                }
            }

            $this->statuses->sync($locked);

            return new RefundResult($locked->refresh(), $targets->values()->all(), $reported, $refundId, $failed, $partial);
        });

        if ($notify) {
            $this->notifyBuyer($result->order, $result->amountCents, $onlySellerOrderId !== null ? $result->sellerOrders[0] : null, $buyerNote);
        }

        return $result;
    }

    /**
     * Record a refund of $amountCents of a seller order: the money flow (see the class comment), entitlements and status.
     * A refund that covers what is left of the seller order is a full one (status refunded).
     *
     * @return bool false when the payout transfer could not be reversed
     */
    private function applyRefund(SellerOrder $sellerOrder, ?string $refundId, int $amountCents): bool
    {
        $full = $amountCents >= $sellerOrder->refundableCents();
        $transferred = $sellerOrder->isTransferred() && filled($sellerOrder->stripe_transfer_id);
        $left = max(0, $sellerOrder->payout_cents - $sellerOrder->reversed_cents); // of the transfer, once paid out

        $reversalId = $sellerOrder->stripe_reversal_id;
        $reversed = true;
        $reverseCents = 0;
        $pendingCents = 0;
        $payoutCents = $sellerOrder->payout_cents;

        if ($transferred) {
            // After the payout: take back that amount of the transfer (everything that is left, for a full refund).
            $reverseCents = $full ? $left : min($amountCents, $left);
        } elseif (! $full) {
            // Before the payout: the seller is paid that much less, the fee stays with the platform.
            $payoutCents = max(0, $payoutCents - $amountCents);
        }
        // A full refund before the payout needs nothing else: a refunded seller order is never paid out, so the fee is waived too.

        if ($reverseCents > 0) {
            try {
                $reversalId = $this->gateway->reverseTransfer($sellerOrder->stripe_transfer_id, $reverseCents, [
                    'order_id' => $sellerOrder->order_id,
                    'seller_order_id' => $sellerOrder->getKey(),
                    'idempotency_key' => $this->reversalKey($sellerOrder, $reverseCents),
                ]);
            } catch (PaymentGatewayException $e) {
                report($e);
                Log::error('Could not reverse a seller payout after a refund.', ['seller_order_id' => $sellerOrder->getKey()]);
                $reversed = false;
                $pendingCents = $reverseCents;
            }
        }

        $sellerOrder->forceFill([
            'status' => $full ? SellerOrderStatus::REFUNDED : $sellerOrder->status,
            'refunded_cents' => $sellerOrder->refunded_cents + $amountCents,
            'payout_cents' => $payoutCents,
            'stripe_refund_id' => $refundId,
            'stripe_reversal_id' => $reversalId,
            'reversed_cents' => $sellerOrder->reversed_cents + ($reversed ? $reverseCents : 0),
            'reversal_pending_cents' => $sellerOrder->reversal_pending_cents + $pendingCents,
            'refunded_at' => now(),
        ])->save();

        if (! $full) {
            return $reversed;
        }

        $buyer = $sellerOrder->order()->first()?->user;
        foreach ($sellerOrder->items as $item) {
            if (! $item->is_digital) {
                continue;
            }

            $purchasable = $item->purchasable;
            if ($buyer !== null && $purchasable !== null) {
                $buyer->revokeEntitlement($purchasable);
            }
        }

        return $reversed;
    }

    /** Idempotency key of a transfer reversal: the first one of a seller order keeps the short historic form. */
    private function reversalKey(SellerOrder $sellerOrder, int $amountCents): string
    {
        return 'reverse-seller-order-'.$sellerOrder->getKey()
            .($sellerOrder->reversed_cents > 0 ? '-'.$sellerOrder->reversed_cents.'-'.$amountCents : '');
    }

    private function notifyBuyer(Order $order, int $amountCents, ?SellerOrder $sellerOrder, ?string $note = null): void
    {
        try {
            $order->user->notify(new OrderRefunded($order, $amountCents, $sellerOrder, $note));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
