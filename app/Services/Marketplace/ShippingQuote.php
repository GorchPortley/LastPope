<?php

namespace App\Services\Marketplace;

/**
 * Result of ShippingCalculator::quote().
 *
 * `lines` maps each input key to its shipping in cents (0 for digital, null when unknown or not
 * shippable). `unshippable` lists the keys the seller will not ship to the chosen country;
 * `awaitingAddress` the physical keys that cannot be priced because no country was given.
 * Lines fulfilled by a supplier are priced as one parcel per supplier (see ShippingCalculator): `lines` holds each
 * line's share of it and `supplierParcels` the parcels.
 */
final class ShippingQuote
{
    /**
     * @param  array<int|string, int|null>  $lines
     * @param  array<int, int|string>  $unshippable
     * @param  array<int, int|string>  $awaitingAddress
     * @param  array<int, int>  $perSeller  seller id (0 = unknown) => shipping cents
     * @param  array<int, array{cents: ?int, lines: array<int, int|string>}>  $supplierParcels  supplier id => the parcel of
     *                                                                                          dropshipped lines: its shipping (null = not shippable / unknown) and the line keys
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $unshippable,
        public readonly array $awaitingAddress,
        public readonly array $perSeller,
        public readonly array $supplierParcels = [],
    ) {
    }

    public function totalCents(): int
    {
        return array_sum($this->perSeller);
    }

    public function forSeller(?int $sellerId): int
    {
        return $this->perSeller[$sellerId ?? 0] ?? 0;
    }

    /** Every line could be priced. */
    public function isComplete(): bool
    {
        return $this->unshippable === [] && $this->awaitingAddress === [];
    }
}
