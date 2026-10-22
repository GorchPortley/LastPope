<?php

namespace App\Services\Marketplace\Data;

use App\Enums\FulfillmentMode;
use App\Enums\FulfillmentSource;
use App\Models\Supplier;
use Illuminate\Support\Str;

/**
 * Where a quantity of a listing would be fulfilled from, and whether it can be bought at all (docs/PLAN.md 8.1):
 * the answer of Listing::fulfillmentFor($quantity). Pure data; $supplier is internal and must never be shown to buyers.
 */
final class FulfillmentQuote
{
    /**
     * @param  FulfillmentSource  $source  what fulfils $quantity (when it cannot be bought: the source that comes closest)
     * @param  bool  $available  $quantity units can be bought now from $source
     * @param  int  $maxQuantity  the most units one order can hold, from any source (0 = sold out)
     * @param  int  $leadTimeMinDays  own stock: the listing's handling days (both ends); supplier: its business days range
     */
    public function __construct(
        public readonly FulfillmentMode $mode,
        public readonly int $quantity,
        public readonly FulfillmentSource $source,
        public readonly bool $available,
        public readonly int $maxQuantity,
        public readonly int $leadTimeMinDays,
        public readonly int $leadTimeMaxDays,
        public readonly ?Supplier $supplier = null,
    ) {
    }

    public function isStock(): bool
    {
        return $this->source === FulfillmentSource::STOCK;
    }

    public function isSupplier(): bool
    {
        return $this->source === FulfillmentSource::SUPPLIER;
    }

    /** The supplier's business days range as a phrase ("5–8 business days"), "" for own stock. */
    public function leadTimeText(): string
    {
        return $this->isSupplier() ? Supplier::formatLeadTime($this->leadTimeMinDays, $this->leadTimeMaxDays) : '';
    }

    /**
     * The sentence for the buy box and the cart: "In stock, ships in 3 days", "Ships in 5–8 business days",
     * "Out of stock" or "Only 2 available".
     */
    public function shippingText(): string
    {
        if (! $this->available) {
            return $this->maxQuantity > 0 ? 'Only '.$this->maxQuantity.' available' : 'Out of stock';
        }

        if ($this->isStock()) {
            return match (true) {
                $this->leadTimeMaxDays <= 0 => 'In stock, ships today',
                default => 'In stock, ships in '.$this->leadTimeMaxDays.' '.Str::plural('day', $this->leadTimeMaxDays),
            };
        }

        $lead = $this->leadTimeText();

        return $lead === '' ? 'Ships soon' : 'Ships in '.$lead;
    }
}
