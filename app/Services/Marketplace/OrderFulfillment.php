<?php

namespace App\Services\Marketplace;

use App\Enums\EntitlementSource;
use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Models\Cart;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use App\Notifications\NewSale;
use App\Notifications\OrderReceipt;
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
    ) {
    }

    /**
     * Mark the order paid and fulfil it. Idempotent: the order row is locked and nothing happens when
     * it is already paid (Stripe may deliver the event twice, or both checkout.session.completed and
     * async_payment_succeeded). Returns true when this call did the fulfilment.
     *
     *  - digital items: entitlement for the buyer (source: purchase)
     *  - listings: stock decremented, the listing becomes sold_out at zero
     *  - seller orders: digital-only -> completed, with physical items -> awaiting_shipment
     *  - buyer's cart: the ordered items are removed
     *  - payouts: due seller orders are transferred (digital now; physical on shipment unless the
     *    payout trigger is "paid"); retried when the event is redelivered
     *  - notifications: receipt to the buyer, new-sale to every seller
     */
    public function markPaid(Order $order, string $chargeId, string $paymentIntentId): bool
    {
        $fulfilled = DB::transaction(function () use ($order, $chargeId, $paymentIntentId) {
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

            foreach ($locked->sellerOrders as $sellerOrder) {
                foreach ($sellerOrder->items as $item) {
                    $this->fulfilItem($locked, $item);
                }

                $sellerOrder->forceFill([
                    'status' => $sellerOrder->hasPhysicalItems()
                        ? SellerOrderStatus::AWAITING_SHIPMENT
                        : SellerOrderStatus::COMPLETED,
                ])->save();
            }

            $this->removeFromCart($locked);
            $this->statuses->sync($locked);

            return true;
        });

        $order->refresh();

        if ($fulfilled) {
            $this->notify($order);
        }

        // Also on a redelivered event: picks up payouts that failed the first time.
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

    private function fulfilItem(Order $order, OrderItem $item): void
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

        $listing = Listing::withTrashed()->whereKey($item->purchasable_id)->lockForUpdate()->first();

        if ($listing === null) {
            Log::error('Paid listing is gone; stock not decremented.', ['order_item_id' => $item->getKey()]);

            return;
        }

        if ($listing->quantity < $item->quantity) {
            // Two buyers paid for the last unit at the same time. Fulfil, flag it for the admin.
            Log::warning('Listing oversold.', ['listing_id' => $listing->getKey(), 'stock' => $listing->quantity, 'ordered' => $item->quantity, 'order' => $order->number]);
        }

        $listing->quantity = max(0, $listing->quantity - $item->quantity);
        if ($listing->quantity === 0 && $listing->status === ListingStatus::ACTIVE) {
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
            try {
                $sellerOrder->seller?->notify(new NewSale($sellerOrder));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
