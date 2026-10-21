<?php

namespace App\Services\Marketplace;

/**
 * Result of ShippingCalculator::quote().
 *
 * `lines` maps each input key to its shipping in cents (0 for digital, null when unknown or not
 * shippable). `unshippable` lists the keys the seller will not ship to the chosen country;
 * `awaitingAddress` the physical keys that cannot be priced because no country was given.
 */
final class ShippingQuote
{
    /**
     * @param  array<int|string, int|null>  $lines
     * @param  array<int, int|string>  $unshippable
     * @param  array<int, int|string>  $awaitingAddress
     * @param  array<int, int>  $perSeller  seller id (0 = unknown) => shipping cents
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $unshippable,
        public readonly array $awaitingAddress,
        public readonly array $perSeller,
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
