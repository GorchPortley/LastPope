<?php

namespace App\Services\Marketplace\Data;

use App\Models\OrderItem;
use App\Models\Shipment;
use Carbon\CarbonInterface;
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
        public readonly ?int $leadTimeMinDays = null,
        public readonly ?int $leadTimeMaxDays = null,
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

    /** Some, but not all, of the units are in a shipment. */
    public function isPartlyShipped(): bool
    {
        return ! $this->complete && $this->shipments->isNotEmpty();
    }

    /**
     * When the buyer can expect this parcel to ship: a supplier's lead time counted in business days from $from (the day
     * the order was paid), as "Expected to ship between Oct 28 and Nov 3 (5–8 business days)". Null when there is no lead
     * time to promise (own stock, or a supplier without one).
     */
    public function expectedText(?CarbonInterface $from = null): ?string
    {
        if ($this->leadTimeMaxDays === null || $this->leadTimeMaxDays <= 0 || $this->detail === '') {
            return null;
        }

        if ($from === null) {
            return 'Expected to ship in '.$this->detail;
        }

        $earliest = $from->copy()->addWeekdays(max(0, (int) $this->leadTimeMinDays));
        $latest = $from->copy()->addWeekdays($this->leadTimeMaxDays);

        return 'Expected to ship '.($earliest->isSameDay($latest)
            ? 'by '.$latest->format('M j')
            : 'between '.$earliest->format('M j').' and '.$latest->format('M j')).' ('.$this->detail.')';
    }
}
