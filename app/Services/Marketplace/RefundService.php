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
 * refundOrder() and refundSellerOrder() authorize through the `refund` policy ability (admins only).
 * refundSellerOrderAutomatically() is the system path (no user, no authorization) used when the goods
 * could not be delivered. Gateway failures propagate as PaymentGatewayException before anything is
 * changed locally.
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

    /** Try again to take the payout back from a seller after RefundResult::$reversalFailed. */
    public function retryTransferReversal(User $actor, SellerOrder $sellerOrder): bool
    {
        Gate::forUser($actor)->authorize('refund', $sellerOrder);

        if (! $sellerOrder->isRefunded() || ! $sellerOrder->stripe_transfer_id || $sellerOrder->stripe_reversal_id) {
            return false;
        }

        $id = $this->gateway->reverseTransfer($sellerOrder->stripe_transfer_id, $sellerOrder->payout_cents, [
            'seller_order_id' => $sellerOrder->getKey(),
            'idempotency_key' => 'reverse-seller-order-'.$sellerOrder->getKey(),
        ]);

        $sellerOrder->forceFill(['stripe_reversal_id' => $id])->save();

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
                $this->applyRefund($sellerOrder, null, $sellerOrder->grossCents());
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
     */
    private function process(Order $order, ?int $onlySellerOrderId, ?string $reason, ?User $actor, ?string $buyerNote = null): RefundResult
    {
        $result = DB::transaction(function () use ($order, $onlySellerOrderId, $reason, $actor) {
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

            $gross = (int) $targets->sum(fn (SellerOrder $so) => $so->grossCents());
            $wholeUntouchedOrder = $targets->count() === $open->count() && $open->count() === $all->count();

            // Refunding the entire payment (tax included) is a full refund; otherwise items + shipping of the targets.
            $amount = $wholeUntouchedOrder ? null : $gross;
            $reported = $wholeUntouchedOrder ? $locked->total_cents : $gross;

            $key = $onlySellerOrderId !== null
                ? 'refund-seller-order-'.$onlySellerOrderId
                : 'refund-order-'.$locked->getKey().'-'.($amount ?? 'full');

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
                if (! $this->applyRefund($sellerOrder, $refundId, $sellerOrder->grossCents())) {
                    $failed[] = $sellerOrder;
                }
            }

            $this->statuses->sync($locked);

            return new RefundResult($locked->refresh(), $targets->values()->all(), $reported, $refundId, $failed);
        });

        $this->notifyBuyer($result->order, $result->amountCents, $onlySellerOrderId !== null ? $result->sellerOrders[0] : null, $buyerNote);

        return $result;
    }

    /**
     * Record a seller order's refund: status, entitlements, and the payout taken back.
     *
     * @return bool false when the payout transfer could not be reversed
     */
    private function applyRefund(SellerOrder $sellerOrder, ?string $refundId, int $grossCents): bool
    {
        $reversalId = null;
        $reversed = true;

        if ($sellerOrder->isTransferred() && filled($sellerOrder->stripe_transfer_id) && $sellerOrder->payout_cents > 0) {
            try {
                $reversalId = $this->gateway->reverseTransfer($sellerOrder->stripe_transfer_id, $sellerOrder->payout_cents, [
                    'order_id' => $sellerOrder->order_id,
                    'seller_order_id' => $sellerOrder->getKey(),
                    'idempotency_key' => 'reverse-seller-order-'.$sellerOrder->getKey(),
                ]);
            } catch (PaymentGatewayException $e) {
                report($e);
                Log::error('Could not reverse a seller payout after a refund.', ['seller_order_id' => $sellerOrder->getKey()]);
                $reversed = false;
            }
        }

        $sellerOrder->forceFill([
            'status' => SellerOrderStatus::REFUNDED,
            'refunded_cents' => $grossCents,
            'stripe_refund_id' => $refundId,
            'stripe_reversal_id' => $reversalId,
            'refunded_at' => now(),
        ])->save();

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

    private function notifyBuyer(Order $order, int $amountCents, ?SellerOrder $sellerOrder, ?string $note = null): void
    {
        try {
            $order->user->notify(new OrderRefunded($order, $amountCents, $sellerOrder, $note));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
