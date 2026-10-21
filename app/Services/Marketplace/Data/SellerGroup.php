<?php

namespace App\Services\Marketplace\Data;

use App\Models\User;

/**
 * The part of a cart (and later of an order) that belongs to one seller. Lines without a known
 * seller are grouped under a null seller.
 */
final class SellerGroup
{
    /**
     * @param  array<int, CartLine>  $lines
     * @param  bool  $shippingPending  physical lines exist but the shipping cost is not known yet
     */
    public function __construct(
        public readonly ?User $seller,
        public readonly string $displayName,
        public readonly array $lines,
        public readonly int $subtotalCents,
        public readonly int $shippingCents,
        public readonly bool $shippingPending,
        public readonly int $feeCents,
        public readonly int $payoutCents,
        public readonly bool $isPlatform,
    ) {
    }

    /** What the buyer pays this seller: items plus shipping (before tax). */
    public function totalCents(): int
    {
        return $this->subtotalCents + $this->shippingCents;
    }

    public function hasPhysicalItems(): bool
    {
        foreach ($this->lines as $line) {
            if (! $line->isDigital) {
                return true;
            }
        }

        return false;
    }
}
