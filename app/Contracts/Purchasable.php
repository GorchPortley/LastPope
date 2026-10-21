<?php

namespace App\Contracts;

use App\Models\User;

/**
 * Anything that can be put in a cart and bought: Design (digital) and Listing (physical).
 */
interface Purchasable
{
    public function purchasableTitle(): string;

    public function purchasablePriceCents(): int;

    /** Design->designer, Listing->seller. */
    public function getSeller(): ?User;

    public function isDigital(): bool;

    public function requiresShipping(): bool;

    /** Active + seller can be paid (or price 0) + deliverable/stock. */
    public function isAvailableForPurchase(): bool;
}
