<?php

namespace App\Services\Marketplace;

use App\Contracts\Purchasable;
use App\Models\Listing;
use App\Services\Marketplace\Data\FulfillmentQuote;

/**
 * Shipping for physical lines (docs/PLAN.md 3.4 and 8.2): base = domestic when the buyer's country equals
 * the listing's ships_from_country, otherwise international (null = the seller does not ship there);
 * line shipping = base + (qty - 1) x additional_item_shipping_cents. A seller's shipping is the sum
 * of its lines. Digital products ship for free.
 *
 * Lines that a supplier fulfils (dropshipped store listings, decided per line at checkout) do not use the listing's
 * own rules: all lines of one supplier form ONE parcel priced with the supplier's rules (first unit domestic or
 * international by the buyer's country, + additional_item_shipping_cents per further unit, free at
 * free_shipping_over_cents). Each line then carries its share of the parcel's price, so line shares add up exactly.
 */
class ShippingCalculator
{
    /**
     * Shipping for one line. 0 for digital products; null when the seller does not ship to
     * $country or when $country is not known yet. Dropshipped lines are priced by quote(), as a parcel.
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
     * @param  iterable<int|string, array{0: Purchasable, 1: int, 2?: FulfillmentQuote|null}>  $lines  [purchasable, quantity, where it
     *                                                                                                 is fulfilled from]. Without the third element a
     *                                                                                                 listing is asked (Listing::fulfillmentFor()).
     */
    public function quote(iterable $lines, ?string $country): ShippingQuote
    {
        $country = $country !== null && $country !== '' ? strtoupper($country) : null;

        $perLine = [];
        $unshippable = [];
        $awaitingAddress = [];
        $perSeller = [];
        /** @var array<int, array{supplier: \App\Models\Supplier, sellerId: int, lines: array<int|string, array{0: Listing, 1: int}>}> $parcels */
        $parcels = [];

        foreach ($lines as $key => $line) {
            [$purchasable, $quantity] = $line;
            $quantity = max(1, (int) $quantity);

            if ($purchasable instanceof Listing && $purchasable->fulfillmentMode()->usesSupplier()) {
                $fulfillment = $line[2] ?? $purchasable->fulfillmentFor($quantity);

                if ($fulfillment->isSupplier() && $fulfillment->supplier !== null) {
                    $sellerId = $purchasable->getSeller()?->getKey() ?? 0;
                    $parcels[$fulfillment->supplier->getKey()] ??= ['supplier' => $fulfillment->supplier, 'sellerId' => $sellerId, 'lines' => []];
                    $parcels[$fulfillment->supplier->getKey()]['lines'][$key] = [$purchasable, $quantity];

                    continue;
                }
            }

            $cents = $this->lineCents($purchasable, $quantity, $country);
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

        $supplierParcels = [];

        foreach ($parcels as $supplierId => $parcel) {
            $shares = $this->parcelShares($parcel['supplier'], $parcel['lines'], $country);
            $supplierParcels[$supplierId] = ['cents' => $shares === null ? null : array_sum($shares), 'lines' => array_keys($parcel['lines'])];

            foreach ($parcel['lines'] as $key => $_) {
                $perLine[$key] = $shares[$key] ?? null;

                if ($shares === null) {
                    if ($country === null) {
                        $awaitingAddress[] = $key;
                    } else {
                        $unshippable[] = $key;
                    }
                }
            }

            if ($shares !== null) {
                $perSeller[$parcel['sellerId']] = ($perSeller[$parcel['sellerId']] ?? 0) + array_sum($shares);
            }
        }

        return new ShippingQuote($perLine, $unshippable, $awaitingAddress, $perSeller, $supplierParcels);
    }

    /**
     * What one supplier parcel costs the buyer, split over its lines: null when it cannot be priced (no country yet) or
     * the supplier does not ship there. The first unit carries the base price, every further unit the additional price,
     * and the last line takes the rounding so the shares add up to the parcel price.
     *
     * @param  array<int|string, array{0: Listing, 1: int}>  $lines
     * @return array<int|string, int>|null
     */
    private function parcelShares(\App\Models\Supplier $supplier, array $lines, ?string $country): ?array
    {
        if ($country === null) {
            return null;
        }

        $units = (int) array_sum(array_map(fn (array $line) => $line[1], $lines));
        $subtotal = (int) array_sum(array_map(fn (array $line) => $line[0]->purchasablePriceCents() * $line[1], $lines));

        $total = $supplier->shippingCentsFor($country, $units, $subtotal);
        if ($total === null) {
            return null;
        }

        $base = (int) $supplier->shippingCentsFor($country, 1, $subtotal);
        $additional = $units > 1 ? intdiv($total - $base, $units - 1) : 0;

        $shares = [];
        $allocated = 0;
        $last = array_key_last($lines);

        foreach ($lines as $key => [$listing, $quantity]) {
            $first = $allocated === 0 && $shares === [];
            $share = $key === $last ? $total - $allocated : ($first ? $base + ($quantity - 1) * $additional : $quantity * $additional);
            $shares[$key] = $share;
            $allocated += $share;
        }

        return $shares;
    }
}
