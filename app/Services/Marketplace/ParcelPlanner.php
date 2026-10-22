<?php

namespace App\Services\Marketplace;

use App\Models\OrderItem;
use App\Models\SellerOrder;
use App\Models\Shipment;
use App\Services\Marketplace\Data\Parcel;
use Illuminate\Support\Collection;

/**
 * Works out the parcels of a seller order from its physical lines (docs/PLAN.md 8.3): the lines fulfilled from own stock
 * (or by a marketplace seller) form one parcel, every supplier's lines another. Lines whose purchase-order line was
 * cancelled are not expected any more. A seller order is fully shipped when nothing is left to ship (remaining()).
 */
class ParcelPlanner
{
    /**
     * @return Collection<int, Parcel> own stock first, then the suppliers
     */
    public function parcels(SellerOrder $sellerOrder): Collection
    {
        $sellerOrder->loadMissing(['seller.sellerProfile', 'items.supplierOrderItem', 'items.supplier', 'shipments.items']);
        $shipped = $this->shippedQuantities($sellerOrder);

        $groups = $sellerOrder->items
            ->reject(fn (OrderItem $item) => $item->is_digital || $item->supplierOrderItem?->isCancelled())
            ->groupBy(fn (OrderItem $item) => $item->fulfilmentKey())
            ->sortBy(fn ($items, string $key) => $key === 'stock' ? 0 : (int) substr($key, strlen('supplier:')));

        $storeName = (string) config('marketplace.store_name', 'SDLabs');
        $owner = ($sellerOrder->seller?->isPlatformSeller() ?? false)
            ? $storeName
            : ($sellerOrder->seller?->sellerProfile?->shop_name ?: ($sellerOrder->seller?->name ?? 'the seller'));

        return $groups->map(function (Collection $items, string $key) use ($sellerOrder, $shipped, $owner) {
            $supplier = $key === 'stock' ? null : $items->first()->supplier;
            $itemIds = $items->pluck('id');

            return new Parcel(
                key: $key,
                name: $key === 'stock' ? 'Ships from '.$owner : 'Ships separately',
                detail: $supplier?->leadTimeText() ?? '',
                items: $items->values(),
                shippingCents: (int) $items->sum('shipping_cents'),
                shipments: $sellerOrder->shipments->filter(
                    fn (Shipment $shipment) => $shipment->items->contains(fn ($row) => $itemIds->contains($row->order_item_id))
                )->values(),
                complete: $items->every(fn (OrderItem $item) => ($shipped[$item->getKey()] ?? 0) >= $item->quantity),
                supplierId: $key === 'stock' ? null : (int) $items->first()->supplier_id,
                leadTimeMinDays: $supplier?->lead_time_min_days,
                leadTimeMaxDays: $supplier?->lead_time_max_days,
            );
        })->values();
    }

    /** Parcels the buyer should expect in total: at least the shipments made so far. */
    public function expectedParcelCount(SellerOrder $sellerOrder): int
    {
        return max(1, $sellerOrder->shipments()->count(), $this->parcels($sellerOrder)->count());
    }

    /**
     * Units still to be shipped per order item (physical, not cancelled at the supplier), only the items with units left.
     *
     * @return array<int, int> order item id => units left
     */
    public function remaining(SellerOrder $sellerOrder): array
    {
        $sellerOrder->loadMissing(['items.supplierOrderItem', 'shipments.items']);
        $shipped = $this->shippedQuantities($sellerOrder);
        $left = [];

        foreach ($sellerOrder->items as $item) {
            if ($item->is_digital || $item->supplierOrderItem?->isCancelled()) {
                continue;
            }

            $units = $item->quantity - ($shipped[$item->getKey()] ?? 0);
            if ($units > 0) {
                $left[$item->getKey()] = $units;
            }
        }

        return $left;
    }

    /** @return array<int, int> order item id => units that are in a shipment */
    private function shippedQuantities(SellerOrder $sellerOrder): array
    {
        $shipped = [];

        foreach ($sellerOrder->shipments as $shipment) {
            foreach ($shipment->items as $row) {
                $shipped[$row->order_item_id] = ($shipped[$row->order_item_id] ?? 0) + $row->quantity;
            }
        }

        return $shipped;
    }
}
