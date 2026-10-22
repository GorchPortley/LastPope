<?php

namespace App\Services\Marketplace;

use App\Enums\SellerOrderStatus;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Notifications\OrderShipped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Shipping a seller's part of an order (docs/PLAN.md 3.8 step 5 and 8.3).
 *
 * Every shipped parcel is a Shipment (carrier, tracking, items with quantities). A seller order is SHIPPED once every
 * physical item is in a shipment, with shipped_at = the time of the last shipment, so the payout timing, the problem
 * report window and auto-complete keep their meaning. Until then (store orders with several parcels) it is
 * PARTIALLY_SHIPPED and has no shipped_at. seller_orders.carrier / tracking_number / tracking_url mirror the latest shipment.
 *
 *   markShipped()     seller (or any admin, for a platform seller's order such as SDLabs' store sales) enters
 *                     carrier + tracking: ONE shipment with everything that is left to ship from the seller's own hands.
 *                     Calling it again on a shipped order corrects the latest shipment's tracking. The payout follows
 *                     by the payout trigger (default: physical_payout_delay_days after the last parcel shipped)
 *   shipItems()       an admin ships some of a store order's own-stock items as one parcel
 *   updateShipment()  correct the tracking of any parcel
 *   recordShipment()  system path (the purchase orders, seeders): no authorization
 *   confirmReceived() buyer confirms the parcels arrived: shipped -> completed. Never makes the payout earlier
 *   autoComplete()    system: a seller order shipped more than auto_complete_days ago completes by itself, unless
 *                     it has an unresolved problem report
 *
 * Items that a supplier ships (order_items.fulfillment_source = supplier, with a purchase order line) are not shipped
 * here but through PurchaseOrderService::markShipped(), which records the supplier's parcel.
 *
 * The user actions authorize through SellerOrderPolicy and throw AuthorizationException (HTTP 403) for anybody else.
 */
class ShipmentService
{
    public function __construct(
        private readonly PayoutService $payouts,
        private readonly OrderStatusSynchronizer $statuses,
        private readonly ParcelPlanner $planner,
    ) {
    }

    /**
     * @param  User  $actor  the seller, or an admin when the seller order belongs to a platform seller (SellerOrderPolicy::ship)
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException  when $actor may not ship this seller order
     * @throws \Illuminate\Validation\ValidationException  carrier / tracking missing or the URL is not a URL
     * @throws InvalidOrderStateException  nothing to ship (unpaid, digital-only, refunded, completed, or only supplier items left)
     */
    public function markShipped(User $actor, SellerOrder $sellerOrder, string $carrier, string $trackingNumber, ?string $trackingUrl = null): SellerOrder
    {
        Gate::forUser($actor)->authorize('ship', $sellerOrder);

        $data = $this->validateTracking($carrier, $trackingNumber, $trackingUrl);
        $shipment = null;

        DB::transaction(function () use ($sellerOrder, $data, &$shipment) {
            $locked = $this->lock($sellerOrder);

            if (! in_array($locked->status, [SellerOrderStatus::AWAITING_SHIPMENT, SellerOrderStatus::PARTIALLY_SHIPPED, SellerOrderStatus::SHIPPED], true)) {
                throw new InvalidOrderStateException('This order cannot be shipped right now.');
            }

            if ($locked->status === SellerOrderStatus::SHIPPED) {
                // Already shipped: this corrects the tracking of the latest parcel (a legacy order without one gets it now).
                $latest = $locked->shipments()->latest('shipped_at')->latest('id')->first();

                if ($latest === null) {
                    $latest = $this->addShipment($locked, $this->quantitiesOf($locked), $data, null, $locked->shipped_at);
                } else {
                    $latest->forceFill($data)->save();
                    $this->refresh($locked);
                }

                return;
            }

            $shipment = $this->addShipment($locked, $this->ownRemaining($locked), $data, restrictToOwn: true);
        });

        $sellerOrder->refresh();

        if ($shipment !== null) {
            $this->afterShipment($sellerOrder, $shipment);
        }

        return $sellerOrder->fresh();
    }

    /**
     * Ship some items of a seller order as one parcel (an admin, for the store's own-stock items). $quantities maps
     * order item id => units; null ships everything that is left from our own hands. Supplier items that have a purchase
     * order line cannot be shipped here.
     *
     * @param  array<int, int>|null  $quantities
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Illuminate\Validation\ValidationException
     * @throws InvalidOrderStateException
     */
    public function shipItems(User $actor, SellerOrder $sellerOrder, ?array $quantities, string $carrier, string $trackingNumber, ?string $trackingUrl = null): Shipment
    {
        Gate::forUser($actor)->authorize('ship', $sellerOrder);

        return $this->recordShipment($sellerOrder, $quantities, $this->validateTracking($carrier, $trackingNumber, $trackingUrl), restrictToOwn: true);
    }

    /**
     * Record a parcel without authorization: the caller checked who may do this (purchase orders, seeders, jobs).
     * $quantities null = everything that is left to ship (with $restrictToOwn: only what is not waiting for a supplier).
     *
     * @param  array<int, int>|null  $quantities  order item id => units
     * @param  array{carrier: string, tracking_number: string, tracking_url?: ?string, shipped_at?: mixed}  $data
     * @param  bool  $notify  tell the buyer ("parcel 1 of 2")
     *
     * @throws InvalidOrderStateException
     */
    public function recordShipment(SellerOrder $sellerOrder, ?array $quantities, array $data, ?SupplierOrder $supplierOrder = null, bool $restrictToOwn = false, bool $notify = true): Shipment
    {
        $shipment = DB::transaction(function () use ($sellerOrder, $quantities, $data, $supplierOrder, $restrictToOwn) {
            $locked = $this->lock($sellerOrder);

            if (! $locked->status->awaitsShipment() && $locked->status !== SellerOrderStatus::SHIPPED) {
                throw new InvalidOrderStateException('This order cannot be shipped right now.');
            }

            $quantities ??= $restrictToOwn ? $this->ownRemaining($locked) : $this->planner->remaining($locked);

            return $this->addShipment($locked, $quantities, $data, $supplierOrder, restrictToOwn: $restrictToOwn);
        });

        $sellerOrder->refresh();
        $this->afterShipment($sellerOrder, $shipment, $notify);

        return $shipment;
    }

    /**
     * Create the shipment rows and bring the seller order in line (status, shipped_at, tracking mirror). Must run in a
     * transaction with the seller order locked (see lock()); afterShipment() does the rest once that has committed.
     *
     * @param  array<int, int>  $quantities  order item id => units
     * @param  array{carrier: string, tracking_number: string, tracking_url?: ?string, shipped_at?: mixed}  $data
     *
     * @throws InvalidOrderStateException  nothing to ship, an item of another order, or more units than are left
     */
    public function addShipment(SellerOrder $locked, array $quantities, array $data, ?SupplierOrder $supplierOrder = null, mixed $shippedAt = null, bool $restrictToOwn = false): Shipment
    {
        $locked->unsetRelation('shipments');
        $remaining = $this->planner->remaining($locked->unsetRelation('items'));
        $quantities = array_filter($quantities, fn ($units) => (int) $units > 0);

        if ($quantities === []) {
            throw new InvalidOrderStateException($restrictToOwn
                ? 'Everything that is left on this order ships from a supplier: enter the supplier\'s tracking on its purchase order.'
                : 'There is nothing left to ship on this order.');
        }

        $items = $locked->items->keyBy('id');

        foreach ($quantities as $itemId => $units) {
            $item = $items->get($itemId);

            if ($item === null || $item->is_digital) {
                throw new InvalidOrderStateException('One of the items does not belong to this order or is not a physical item.');
            }

            if ($units > ($remaining[$itemId] ?? 0)) {
                throw new InvalidOrderStateException('"'.$item->title.'" has only '.($remaining[$itemId] ?? 0).' unit(s) left to ship.');
            }

            if ($restrictToOwn && $item->isFromSupplier() && $item->supplierOrderItem !== null) {
                throw new InvalidOrderStateException('"'.$item->title.'" ships from a supplier: enter its tracking on the purchase order.');
            }
        }

        $shipment = Shipment::create([
            'seller_order_id' => $locked->getKey(),
            'supplier_order_id' => $supplierOrder?->getKey(),
            'carrier' => $data['carrier'],
            'tracking_number' => $data['tracking_number'],
            'tracking_url' => $data['tracking_url'] ?? null,
            'shipped_at' => $data['shipped_at'] ?? $shippedAt ?? now(),
        ]);

        foreach ($quantities as $itemId => $units) {
            ShipmentItem::create(['shipment_id' => $shipment->getKey(), 'order_item_id' => $itemId, 'quantity' => (int) $units]);
        }

        $this->refresh($locked);

        return $shipment;
    }

    /**
     * Post-commit steps of a new parcel: the order status, the payout (when this parcel completed the shipping) and the
     * buyer's e-mail ("parcel 2 of 2"). A failing mailer never fails the shipping.
     */
    public function afterShipment(SellerOrder $sellerOrder, Shipment $shipment, bool $notify = true): void
    {
        $sellerOrder->refresh();
        $this->statuses->sync($sellerOrder->order);
        $this->payouts->transferIfDue($sellerOrder);

        if (! $notify) {
            return;
        }

        try {
            $sellerOrder->order->user->notify(new OrderShipped($sellerOrder->fresh(['order', 'items']), $shipment));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Correct the carrier / tracking of a parcel (the buyer is not told again).
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function updateShipment(User $actor, Shipment $shipment, string $carrier, string $trackingNumber, ?string $trackingUrl = null): Shipment
    {
        $sellerOrder = $shipment->sellerOrder;
        Gate::forUser($actor)->authorize('ship', $sellerOrder);

        $data = $this->validateTracking($carrier, $trackingNumber, $trackingUrl);

        DB::transaction(function () use ($shipment, $sellerOrder, $data) {
            $shipment->forceFill($data)->save();
            $this->refresh($this->lock($sellerOrder));
        });

        return $shipment->fresh();
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

    /**
     * Complete one shipped seller order on the system's behalf. Returns false if it was not completable,
     * which includes an unresolved problem report: the case has to be settled first.
     */
    public function autoComplete(SellerOrder $sellerOrder): bool
    {
        if ($sellerOrder->hasUnresolvedIssue()) {
            return false;
        }

        try {
            $this->complete($sellerOrder, confirmedByBuyer: false);
        } catch (InvalidOrderStateException) {
            return false;
        }

        return true;
    }

    /**
     * Complete every seller order shipped more than $days (default marketplace.auto_complete_days)
     * ago and without an unresolved problem report. Returns how many were completed.
     */
    public function autoCompleteDue(?int $days = null): int
    {
        $days ??= (int) config('marketplace.auto_complete_days', 14);
        $completed = 0;

        SellerOrder::query()
            ->where('status', SellerOrderStatus::SHIPPED->value)
            ->where('shipped_at', '<=', now()->subDays($days))
            ->whereDoesntHave('issues', fn ($issues) => $issues->unresolved())
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

    /** The seller order row, locked for the transaction it runs in. */
    public function lock(SellerOrder $sellerOrder): SellerOrder
    {
        return SellerOrder::query()->whereKey($sellerOrder->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Units the store itself still has to ship (for the admin's "Ship SDLabs parcel" form): everything that is left except
     * the lines a supplier fulfils through a purchase order.
     *
     * @return array<int, int> order item id => units
     */
    public function ownRemainingQuantities(SellerOrder $sellerOrder): array
    {
        return $this->ownRemaining($sellerOrder);
    }

    /**
     * Units left that nobody but us ships: everything except the lines a supplier fulfils through a purchase order.
     *
     * @return array<int, int> order item id => units
     */
    private function ownRemaining(SellerOrder $locked): array
    {
        $locked->unsetRelation('items')->unsetRelation('shipments');
        $remaining = $this->planner->remaining($locked);

        return array_filter(
            $remaining,
            fn (int $units, int $itemId) => ! ($locked->items->firstWhere('id', $itemId)?->isFromSupplier() && $locked->items->firstWhere('id', $itemId)?->supplierOrderItem !== null),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** @return array<int, int> every physical item of the seller order with its full quantity */
    private function quantitiesOf(SellerOrder $locked): array
    {
        return $locked->items()->where('is_digital', false)->pluck('quantity', 'id')->all();
    }

    /**
     * Bring the seller order in line with its shipments: SHIPPED (shipped_at = the last parcel) when nothing is left to
     * ship, PARTIALLY_SHIPPED while some parcels are out, and the tracking columns mirror the latest parcel.
     */
    private function refresh(SellerOrder $locked): void
    {
        $locked->unsetRelation('shipments')->unsetRelation('items');
        $shipments = $locked->shipments()->get();

        if ($shipments->isEmpty()) {
            return;
        }

        $latest = $shipments->sortBy([['shipped_at', 'asc'], ['id', 'asc']])->last();
        $attributes = [
            'carrier' => $latest->carrier,
            'tracking_number' => $latest->tracking_number,
            'tracking_url' => $latest->tracking_url,
        ];

        if (in_array($locked->status, [SellerOrderStatus::AWAITING_SHIPMENT, SellerOrderStatus::PARTIALLY_SHIPPED, SellerOrderStatus::SHIPPED], true)) {
            $done = $this->planner->remaining($locked) === [];

            $attributes['status'] = $done ? SellerOrderStatus::SHIPPED : SellerOrderStatus::PARTIALLY_SHIPPED;
            $attributes['shipped_at'] = $done ? $shipments->max('shipped_at') : null;
        }

        $locked->forceFill($attributes)->save();
    }

    /**
     * Trim and validate carrier / tracking number / tracking link.
     *
     * @return array{carrier: string, tracking_number: string, tracking_url: ?string}
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validateTracking(string $carrier, string $trackingNumber, ?string $trackingUrl): array
    {
        return Validator::make(
            ['carrier' => trim($carrier), 'tracking_number' => trim($trackingNumber), 'tracking_url' => filled($trackingUrl) ? trim((string) $trackingUrl) : null],
            [
                'carrier' => ['required', 'string', 'max:100'],
                'tracking_number' => ['required', 'string', 'max:100'],
                'tracking_url' => ['nullable', 'url:http,https', 'max:500'],
            ],
        )->validate();
    }

    private function complete(SellerOrder $sellerOrder, bool $confirmedByBuyer): void
    {
        DB::transaction(function () use ($sellerOrder, $confirmedByBuyer) {
            $locked = $this->lock($sellerOrder);

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
