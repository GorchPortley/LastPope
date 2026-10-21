<?php

namespace App\Services\Marketplace;

use App\Enums\SellerOrderStatus;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Models\SellerOrder;
use App\Models\User;
use App\Notifications\OrderShipped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Shipping a seller's part of an order (docs/PLAN.md 3.8 step 5).
 *
 *   markShipped()     seller (or any admin, for a platform seller's order such as SDLabs' store sales) enters
 *                     carrier + tracking: awaiting_shipment -> shipped, payout released (trigger "shipped"),
 *                     buyer notified
 *   confirmReceived() buyer confirms the parcel arrived: shipped -> completed
 *   autoComplete()    system: a parcel shipped more than auto_complete_days ago completes by itself
 *
 * The two user actions authorize through SellerOrderPolicy and throw AuthorizationException (HTTP 403)
 * for anybody else.
 */
class ShipmentService
{
    public function __construct(
        private readonly PayoutService $payouts,
        private readonly OrderStatusSynchronizer $statuses,
    ) {
    }

    /**
     * @param  User  $actor  the seller, or an admin when the seller order belongs to a platform seller (SellerOrderPolicy::ship)
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException  when $actor may not ship this seller order
     * @throws \Illuminate\Validation\ValidationException  carrier / tracking missing or the URL is not a URL
     * @throws InvalidOrderStateException  nothing to ship (unpaid, digital-only, refunded, completed)
     */
    public function markShipped(User $actor, SellerOrder $sellerOrder, string $carrier, string $trackingNumber, ?string $trackingUrl = null): SellerOrder
    {
        Gate::forUser($actor)->authorize('ship', $sellerOrder);

        $data = Validator::make(
            ['carrier' => trim($carrier), 'tracking_number' => trim($trackingNumber), 'tracking_url' => filled($trackingUrl) ? trim((string) $trackingUrl) : null],
            [
                'carrier' => ['required', 'string', 'max:100'],
                'tracking_number' => ['required', 'string', 'max:100'],
                'tracking_url' => ['nullable', 'url:http,https', 'max:500'],
            ],
        )->validate();

        $firstShipment = false;

        DB::transaction(function () use ($sellerOrder, $data, &$firstShipment) {
            /** @var SellerOrder $locked */
            $locked = SellerOrder::query()->whereKey($sellerOrder->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [SellerOrderStatus::AWAITING_SHIPMENT, SellerOrderStatus::SHIPPED], true)) {
                throw new InvalidOrderStateException('This order cannot be shipped right now.');
            }

            $firstShipment = $locked->status === SellerOrderStatus::AWAITING_SHIPMENT;

            $locked->forceFill([
                'status' => SellerOrderStatus::SHIPPED,
                'carrier' => $data['carrier'],
                'tracking_number' => $data['tracking_number'],
                'tracking_url' => $data['tracking_url'],
                'shipped_at' => $locked->shipped_at ?? now(),
            ])->save();
        });

        $sellerOrder->refresh();
        $this->statuses->sync($sellerOrder->order);
        $this->payouts->transferIfDue($sellerOrder);

        if ($firstShipment) {
            try {
                $sellerOrder->order->user->notify(new OrderShipped($sellerOrder->fresh(['order', 'items'])));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $sellerOrder->fresh();
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException  when $buyer is not the buyer of this order
     * @throws InvalidOrderStateException  not shipped yet, or already completed/refunded
     */
    public function confirmReceived(User $buyer, SellerOrder $sellerOrder): SellerOrder
    {
        Gate::forUser($buyer)->authorize('confirmReceived', $sellerOrder);

        $this->complete($sellerOrder, confirmedByBuyer: true);

        return $sellerOrder->fresh();
    }

    /** Complete one shipped seller order on the system's behalf. Returns false if it was not completable. */
    public function autoComplete(SellerOrder $sellerOrder): bool
    {
        try {
            $this->complete($sellerOrder, confirmedByBuyer: false);
        } catch (InvalidOrderStateException) {
            return false;
        }

        return true;
    }

    /**
     * Complete every seller order shipped more than $days (default marketplace.auto_complete_days)
     * ago, paying out any that is still pending. Returns how many were completed.
     */
    public function autoCompleteDue(?int $days = null): int
    {
        $days ??= (int) config('marketplace.auto_complete_days', 14);
        $completed = 0;

        SellerOrder::query()
            ->where('status', SellerOrderStatus::SHIPPED->value)
            ->where('shipped_at', '<=', now()->subDays($days))
            ->orderBy('id')
            ->chunkById(100, function ($sellerOrders) use (&$completed) {
                foreach ($sellerOrders as $sellerOrder) {
                    if ($this->autoComplete($sellerOrder)) {
                        $completed++;
                    }
                }
            });

        return $completed;
    }

    private function complete(SellerOrder $sellerOrder, bool $confirmedByBuyer): void
    {
        DB::transaction(function () use ($sellerOrder, $confirmedByBuyer) {
            /** @var SellerOrder $locked */
            $locked = SellerOrder::query()->whereKey($sellerOrder->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [SellerOrderStatus::SHIPPED, SellerOrderStatus::DELIVERED], true)) {
                throw new InvalidOrderStateException('This order has not been shipped, or is already finished.');
            }

            $locked->forceFill([
                'status' => SellerOrderStatus::COMPLETED,
                'delivered_at' => $confirmedByBuyer ? now() : $locked->delivered_at,
            ])->save();
        });

        $sellerOrder->refresh();
        $this->statuses->sync($sellerOrder->order);
        $this->payouts->transferIfDue($sellerOrder);
    }
}
