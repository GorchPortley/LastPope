<?php

namespace App\Services\Marketplace;

use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Models\Order;

/**
 * Derives a paid order's status from its seller orders. Call it after any seller order changed.
 *
 *   every non-cancelled seller order refunded -> refunded
 *   some refunded                             -> partially_refunded
 *   every non-cancelled seller order completed-> completed
 *   otherwise                                 -> paid
 *
 * Orders that were never paid (pending, cancelled) are left alone.
 */
class OrderStatusSynchronizer
{
    public function sync(Order $order): Order
    {
        if ($order->paid_at === null) {
            return $order;
        }

        $active = $order->sellerOrders()->get()
            ->reject(fn ($sellerOrder) => $sellerOrder->status === SellerOrderStatus::CANCELLED);

        if ($active->isEmpty()) {
            return $order;
        }

        $refunded = $active->filter(fn ($so) => $so->status === SellerOrderStatus::REFUNDED)->count();

        $status = match (true) {
            $refunded === $active->count() => OrderStatus::REFUNDED,
            $refunded > 0 => OrderStatus::PARTIALLY_REFUNDED,
            $active->every(fn ($so) => $so->status === SellerOrderStatus::COMPLETED) => OrderStatus::COMPLETED,
            default => OrderStatus::PAID,
        };

        // A refund made outside the app that we could not attribute to a seller order sticks.
        if ($order->status === OrderStatus::PARTIALLY_REFUNDED && in_array($status, [OrderStatus::PAID, OrderStatus::COMPLETED], true)) {
            return $order;
        }

        if ($order->status !== $status) {
            $order->forceFill(['status' => $status])->save();
        }

        return $order;
    }
}
