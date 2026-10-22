<?php

namespace App\Services\Marketplace\Data;

use App\Models\OrderItem;
use App\Models\Shipment;
use Illuminate\Support\Collection;

/**
 * One parcel of a paid seller order, as the buyer and the admins see it (docs/PLAN.md 8.2/8.3): the physical lines that
 * ship together, what shipping was charged for them and the shipments that already carry them. The label never names
 * the supplier: "Ships from SDLabs" / "Ships separately (5–8 business days)".
 */
final class Parcel
{
    /**
     * @param  string  $key  "stock" or "supplier:{id}"
     * @param  Collection<int, OrderItem>  $items
     * @param  Collection<int, Shipment>  $shipments  the shipments that carry (some of) these items, oldest first
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $detail,
        public readonly Collection $items,
        public readonly int $shippingCents,
        public readonly Collection $shipments,
        public readonly bool $complete,
        public readonly ?int $supplierId = null,
    ) {
    }

    public function label(): string
    {
        return $this->detail === '' ? $this->name : $this->name.' ('.$this->detail.')';
    }

    public function isSupplier(): bool
    {
        return $this->supplierId !== null;
    }

    /** Every unit of this parcel is in a shipment. */
    public function isShipped(): bool
    {
        return $this->complete;
    }
}
