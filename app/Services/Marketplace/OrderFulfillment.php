<?php

namespace App\Services\Marketplace;

use App\Enums\EntitlementSource;
use App\Enums\FulfillmentMode;
use App\Enums\FulfillmentSource;
use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Models\Cart;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Notifications\NewSale;
use App\Notifications\OrderReceipt;
use App\Notifications\SaleCancelled;
use App\Services\Analytics\Ga4Reporter;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a paid order into delivered goods (docs/PLAN.md 3.8 step 4). Called by the Stripe webhook
 * once the payment is confirmed; never from a browser redirect.
 */
class OrderFulfillment
{
    public function __construct(
        private readonly PayoutService $payouts,
        private readonly OrderStatusSynchronizer $statuses,
        private readonly RefundService $refunds,
        private readonly PurchaseOrderService $purchaseOrders,
    ) {
    }

    /**
     * Mark the order paid and fulfil it. Idempotent: the order row is locked and nothing happens when
     * it is already paid (Stripe may deliver the event twice, or both checkout.session.completed and
     * async_payment_succeeded). Returns true when this call did the fulfilment.
     *
     *  - digital items: entitlement for the buyer (source: purchase)
     *  - listings: stock decremented, the listing becomes sold_out at zero (own stock only; a line a supplier ships
     *    lowers the supplier stock instead, when it is known). If a listing can no longer cover a paid line (the seller
     *    lowered the stock, or another buyer got the last unit after this reservation expired), that seller order is
     *    not fulfilled: it is refunded automatically and the seller and buyer are told, while the rest of the order is
     *    fulfilled as usual. A stock-then-dropship line that own stock cannot cover any more goes to the supplier instead
     *  - purchase orders: one per supplier for the lines a supplier ships (idempotent); suppliers set to auto-send get
     *    the e-mail, for the others the admins are told how many purchase orders wait
     *  - seller orders: digital-only -> completed, with physical items -> awaiting_shipment
     *  - buyer's cart: the ordered items are removed
     *  - payouts: due seller orders are transferred (digital now; physical on shipment unless the
     *    payout trigger is "paid"); retried when the event is redelivered
     *  - notifications: receipt to the buyer, new-sale to every seller
 *  - analytics: the GA4 `purchase` event is queued (Ga4Reporter; skipped without consent)
     */
    public function markPaid(Order $order, string $chargeId, string $paymentIntentId): bool
    {
        $newPurchaseOrders = collect();

        $fulfilled = DB::transaction(function () use ($order, $chargeId, $paymentIntentId, &$newPurchaseOrders) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [OrderStatus::PENDING, OrderStatus::CANCELLED], true)) {
                return false;
            }

            if ($locked->status === OrderStatus::CANCELLED) {
                Log::warning('A cancelled order was paid; fulfilling it.', ['order' => $locked->number]);
            }

            $locked->forceFill([
                'status' => OrderStatus::PAID,
                'paid_at' => now(),
                'stripe_charge_id' => $chargeId,
                'stripe_payment_intent_id' => $paymentIntentId,
            ])->save();

            $locked->load(['user', 'sellerOrders.items']);
            $listings = $this->lockListings($locked);

            foreach ($locked->sellerOrders as $sellerOrder) {
                $this->moveShortHybridLinesToSupplier($sellerOrder, $listings);

                if ($this->outOfStock($sellerOrder, $listings)) {
                    // Paid, but the goods are gone: PAID marks it for refundUnfulfillable() right after this transaction.
                    $sellerOrder->forceFill(['status' => SellerOrderStatus::PAID])->save();
                    Log::warning('Paid seller order cannot be fulfilled: out of stock.', ['order' => $locked->number, 'seller_order_id' => $sellerOrder->getKey()]);

                    continue;
                }

                foreach ($sellerOrder->items as $item) {
                    $this->fulfilItem($locked, $item, $listings);
                }

                $sellerOrder->forceFill([
                    'status' => $sellerOrder->hasPhysicalItems()
                        ? SellerOrderStatus::AWAITING_SHIPMENT
                        : SellerOrderStatus::COMPLETED,
                ])->save();
            }

            $newPurchaseOrders = $this->purchaseOrders->createForOrder($locked);

            $this->removeFromCart($locked);
            $this->statuses->sync($locked);

            return true;
        });

        $order->refresh();

        if ($fulfilled) {
            $this->notify($order);
            $this->purchaseOrders->processNew($newPurchaseOrders);
            // Analytics (docs/PLAN.md 12): queued, idempotent, never throws.
            app(Ga4Reporter::class)->purchase($order);
        }

        // Also on a redelivered event: retries refunds and payouts that failed the first time.
        $this->refundUnfulfillable($order);
        $this->payouts->releaseDueFor($order);

        return $fulfilled;
    }

    /**
     * Give up on an unpaid order (its Checkout Session expired, or could not be created). A paid order
     * is never touched. Returns true when the order was cancelled now.
     */
    public function cancelPending(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::PENDING) {
                return false;
            }

            $locked->forceFill(['status' => OrderStatus::CANCELLED])->save();
            $locked->sellerOrders()->update(['status' => SellerOrderStatus::CANCELLED->value]);
            $order->refresh();

            return true;
        });
    }

    /**
     * Refund the seller orders of this paid order that could not be fulfilled (status PAID, see markPaid()),
     * and tell the seller. Safe to repeat; a refund that fails stays PAID and is retried later.
     *
     * @return int seller orders refunded now
     */
    public function refundUnfulfillable(Order $order): int
    {
        $refunded = 0;

        $stuck = $order->sellerOrders()->where('status', SellerOrderStatus::PAID->value)->with(['order', 'seller'])->get();

        foreach ($stuck as $sellerOrder) {
            $reason = 'one of the items is no longer in stock';

            try {
                $this->refunds->refundSellerOrderAutomatically($sellerOrder, ucfirst($reason).', so this part of your order was refunded automatically.');
            } catch (PaymentGatewayException|InvalidOrderStateException $e) {
                report($e);
                Log::critical('Automatic refund of an unfulfillable seller order failed; it will be retried.', ['seller_order_id' => $sellerOrder->getKey()]);

                continue;
            }

            $refunded++;

            try {
                $sellerOrder->seller?->notify(new SaleCancelled($sellerOrder->fresh(['order', 'items']), $reason));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $refunded;
    }

    /** Retry every pending automatic refund (scheduled). @return int seller orders refunded now */
    public function retryUnfulfillableRefunds(): int
    {
        $refunded = 0;

        Order::query()
            ->whereIn('id', SellerOrder::query()->where('status', SellerOrderStatus::PAID->value)->select('order_id'))
            ->whereNotNull('paid_at')
            ->get()
            ->each(function (Order $order) use (&$refunded) {
                $refunded += $this->refundUnfulfillable($order);
            });

        return $refunded;
    }

    /**
     * Cancel pending orders whose stock hold ended more than $hours ago, in case the
     * checkout.session.expired webhook never came (the hold itself ends by the clock, without this).
     *
     * @return int orders cancelled
     */
    public function cancelStalePending(int $hours = 2): int
    {
        $cancelled = 0;

        Order::query()
            ->where('status', OrderStatus::PENDING->value)
            ->where('reserved_until', '<', now()->subHours($hours))
            ->orderBy('id')
            ->chunkById(100, function ($orders) use (&$cancelled) {
                foreach ($orders as $order) {
                    if ($this->cancelPending($order)) {
                        $cancelled++;
                    }
                }
            });

        return $cancelled;
    }

    /**
     * Lock the listings of the order's physical lines (in id order, against deadlocks).
     *
     * @return Collection<int, Listing>
     */
    private function lockListings(Order $order): Collection
    {
        $ids = $order->sellerOrders
            ->flatMap(fn (SellerOrder $sellerOrder) => $sellerOrder->items)
            ->reject(fn (OrderItem $item) => $item->is_digital)
            ->pluck('purchasable_id')
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return Listing::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /** @param  Collection<int, Listing>  $listings */
    private function outOfStock(SellerOrder $sellerOrder, Collection $listings): bool
    {
        foreach ($sellerOrder->items as $item) {
            if ($item->is_digital) {
                continue;
            }

            // A line a supplier ships does not need our stock; the purchase order takes care of it.
            if ($item->isFromSupplier()) {
                continue;
            }

            $listing = $listings->get($item->purchasable_id);

            if ($listing === null || $listing->quantity < $item->quantity) {
                return true;
            }
        }

        return false;
    }

    /**
     * A stock-then-dropship line was decided to come from own stock at checkout, but the stock does not cover it any
     * more (it was sold in between, or the stock was corrected): the supplier ships it instead of refunding the buyer.
     * The shipping the buyer paid stays as charged.
     *
     * @param  Collection<int, Listing>  $listings
     */
    private function moveShortHybridLinesToSupplier(SellerOrder $sellerOrder, Collection $listings): void
    {
        foreach ($sellerOrder->items as $item) {
            if ($item->is_digital || $item->isFromSupplier()) {
                continue;
            }

            $listing = $listings->get($item->purchasable_id);

            if ($listing === null
                || $listing->fulfillmentMode() !== FulfillmentMode::STOCK_THEN_DROPSHIP
                || $listing->quantity >= $item->quantity
                || ! $listing->supplierUsable()) {
                continue;
            }

            $item->forceFill(['fulfillment_source' => FulfillmentSource::SUPPLIER, 'supplier_id' => $listing->supplier_id])->save();
            Log::notice('A stock-then-dropship line moved to the supplier at payment: own stock no longer covers it.', ['order_item_id' => $item->getKey()]);
        }
    }

    /** @param  Collection<int, Listing>  $listings */
    private function fulfilItem(Order $order, OrderItem $item, Collection $listings): void
    {
        if ($item->is_digital) {
            $purchasable = $item->purchasable;

            if ($purchasable === null || ! $purchasable->isDigital()) {
                Log::error('Paid digital item could not be delivered: the product is gone.', ['order_item_id' => $item->getKey()]);

                return;
            }

            $order->user->grantEntitlement($purchasable, EntitlementSource::PURCHASE, $item);

            return;
        }

        // outOfStock() already checked that the listing exists and covers a line from own stock.
        $listing = $listings->get($item->purchasable_id);

        if ($item->isFromSupplier()) {
            // Never touches our own quantity; a known supplier stock goes down until the next feed sync corrects it.
            if ($listing !== null && $listing->supplier_stock !== null) {
                $listing->supplier_stock = max(0, $listing->supplier_stock - $item->quantity);
                $listing->save();
            }

            return;
        }

        $listing->quantity = max(0, $listing->quantity - $item->quantity);
        // Only a listing that depends on its own stock sells out; one a supplier can cover stays on sale.
        if ($listing->quantity === 0 && $listing->status === ListingStatus::ACTIVE && $listing->fulfillmentMode() === FulfillmentMode::STOCK) {
            $listing->status = ListingStatus::SOLD_OUT;
        }
        $listing->save();
    }

    private function removeFromCart(Order $order): void
    {
        $cart = Cart::query()->where('user_id', $order->user_id)->first();

        if ($cart === null) {
            return;
        }

        foreach ($order->sellerOrders as $sellerOrder) {
            foreach ($sellerOrder->items as $item) {
                $cart->items()
                    ->where('purchasable_type', $item->purchasable_type)
                    ->where('purchasable_id', $item->purchasable_id)
                    ->delete();
            }
        }
    }

    private function notify(Order $order): void
    {
        $order->load(['user', 'sellerOrders.seller', 'sellerOrders.items']);

        try {
            $order->user->notify(new OrderReceipt($order));
        } catch (\Throwable $e) {
            report($e);
        }

        foreach ($order->sellerOrders as $sellerOrder) {
            if ($sellerOrder->status === SellerOrderStatus::PAID) {
                continue; // not fulfilled: the seller hears about the cancellation instead
            }

            try {
                $sellerOrder->seller?->notify(new NewSale($sellerOrder));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
