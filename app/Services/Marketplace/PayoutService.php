<?php

namespace App\Services\Marketplace;

use App\Enums\SellerOrderStatus;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Notifications\PayoutSent;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moves a seller's share to their connected account (docs/PLAN.md 3.8, separate charges and transfers).
 *
 * A seller order is DUE when the buyer paid and
 *  - it contains only digital items (due on payment), or
 *  - it has physical items and the parcel was shipped, or
 *    config('marketplace.physical_payout_trigger') is "paid".
 * Platform sellers (admins) and refunded/cancelled orders are never paid out. Safe to call twice:
 * the row is locked, a transfer id is stored, and Stripe gets an idempotency key.
 */
class PayoutService
{
    public function __construct(private readonly PaymentGateway $gateway)
    {
    }

    public function isDue(SellerOrder $sellerOrder): bool
    {
        $sellerOrder->loadMissing(['order', 'seller', 'items']);

        if ($sellerOrder->isTransferred() || $sellerOrder->payout_cents <= 0) {
            return false;
        }

        if (! in_array($sellerOrder->status, [
            SellerOrderStatus::PAID,
            SellerOrderStatus::AWAITING_SHIPMENT,
            SellerOrderStatus::SHIPPED,
            SellerOrderStatus::DELIVERED,
            SellerOrderStatus::COMPLETED,
        ], true)) {
            return false;
        }

        if (! $sellerOrder->order?->isPaid() || $sellerOrder->seller === null || $sellerOrder->seller->isPlatformSeller()) {
            return false;
        }

        if (! $sellerOrder->hasPhysicalItems()) {
            return true;
        }

        return config('marketplace.physical_payout_trigger') === 'paid'
            || in_array($sellerOrder->status, [SellerOrderStatus::SHIPPED, SellerOrderStatus::DELIVERED, SellerOrderStatus::COMPLETED], true);
    }

    /**
     * Transfer the payout if it is due and not made yet. Returns the transfer id when a transfer was
     * created now, null otherwise (not due, already paid, or Stripe failed: it is retried by
     * releaseDue(), which the daily marketplace:auto-complete command runs). Never throws for Stripe errors.
     */
    public function transferIfDue(SellerOrder $sellerOrder): ?string
    {
        $transferId = null;

        try {
            $transferId = DB::transaction(function () use ($sellerOrder) {
                /** @var SellerOrder|null $locked */
                $locked = SellerOrder::query()->whereKey($sellerOrder->getKey())->lockForUpdate()->first();

                if ($locked === null || ! $this->isDue($locked)) {
                    return null;
                }

                $account = $locked->seller->sellerProfile?->stripe_account_id;
                if (blank($account)) {
                    Log::warning('Payout skipped: seller has no connected account.', ['seller_order_id' => $locked->getKey()]);

                    return null;
                }

                $order = $locked->order;
                $id = $this->gateway->createTransfer(
                    $locked->payout_cents,
                    $account,
                    $order->number,
                    $order->stripe_charge_id,
                    [
                        'order_id' => $order->getKey(),
                        'order_number' => $order->number,
                        'seller_order_id' => $locked->getKey(),
                    ],
                );

                $locked->forceFill(['stripe_transfer_id' => $id, 'transferred_at' => now()])->save();

                return $id;
            });
        } catch (PaymentGatewayException $e) {
            report($e);
            Log::error('Payout transfer failed; it will be retried.', ['seller_order_id' => $sellerOrder->getKey()]);

            return null;
        }

        if ($transferId !== null) {
            $sellerOrder->refresh();
            $this->notifySeller($sellerOrder);
        }

        return $transferId;
    }

    /** Pay out every due seller order of one order. @return int transfers made */
    public function releaseDueFor(Order $order): int
    {
        $made = 0;

        foreach ($order->sellerOrders()->get() as $sellerOrder) {
            if ($this->transferIfDue($sellerOrder) !== null) {
                $made++;
            }
        }

        return $made;
    }

    /** Retry every due payout (scheduled). @return int transfers made */
    public function releaseDue(): int
    {
        $made = 0;

        SellerOrder::query()
            ->whereNull('transferred_at')
            ->where('payout_cents', '>', 0)
            ->whereIn('status', [
                SellerOrderStatus::PAID->value,
                SellerOrderStatus::AWAITING_SHIPMENT->value,
                SellerOrderStatus::SHIPPED->value,
                SellerOrderStatus::DELIVERED->value,
                SellerOrderStatus::COMPLETED->value,
            ])
            ->orderBy('id')
            ->chunkById(100, function ($sellerOrders) use (&$made) {
                foreach ($sellerOrders as $sellerOrder) {
                    if ($this->transferIfDue($sellerOrder) !== null) {
                        $made++;
                    }
                }
            });

        return $made;
    }

    private function notifySeller(SellerOrder $sellerOrder): void
    {
        try {
            $sellerOrder->seller?->notify(new PayoutSent($sellerOrder));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
