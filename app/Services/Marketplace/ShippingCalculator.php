<?php

namespace App\Services\Marketplace;

use App\Contracts\Purchasable;
use App\Models\Listing;

/**
 * Shipping for physical lines (docs/PLAN.md 3.4): base = domestic when the buyer's country equals
 * the listing's ships_from_country, otherwise international (null = the seller does not ship there);
 * line shipping = base + (qty - 1) x additional_item_shipping_cents. A seller's shipping is the sum
 * of its lines. Digital products ship for free.
 */
class ShippingCalculator
{
    /**
     * Shipping for one line. 0 for digital products; null when the seller does not ship to
     * $country or when $country is not known yet.
     */
    public function lineCents(Purchasable $purchasable, int $quantity, ?string $country): ?int
    {
        if (! $purchasable->requiresShipping()) {
            return 0;
        }

        if ($country === null || $country === '' || ! $purchasable instanceof Listing) {
            return null;
        }

        return $purchasable->shippingCentsFor($country, $quantity);
    }

    /**
     * Quote shipping for many lines. Keys of $lines are kept in the answer.
     *
     * @param  iterable<int|string, array{0: Purchasable, 1: int}>  $lines  [purchasable, quantity]
     */
    public function quote(iterable $lines, ?string $country): ShippingQuote
    {
        $country = $country !== null && $country !== '' ? strtoupper($country) : null;

        $perLine = [];
        $unshippable = [];
        $awaitingAddress = [];
        $perSeller = [];

        foreach ($lines as $key => [$purchasable, $quantity]) {
            $cents = $this->lineCents($purchasable, max(1, $quantity), $country);
            $perLine[$key] = $cents;

            if ($cents === null) {
                if ($country === null) {
                    $awaitingAddress[] = $key;
                } else {
                    $unshippable[] = $key;
                }

                continue;
            }

            $sellerId = $purchasable->getSeller()?->getKey() ?? 0;
            $perSeller[$sellerId] = ($perSeller[$sellerId] ?? 0) + $cents;
        }

        return new ShippingQuote($perLine, $unshippable, $awaitingAddress, $perSeller);
    }
}
