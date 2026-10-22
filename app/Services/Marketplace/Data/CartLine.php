<?php

namespace App\Services\Marketplace\Data;

use App\Contracts\Purchasable;
use App\Enums\FulfillmentSource;
use App\Models\CartItem;
use App\Models\User;

/** One cart line, priced at today's price. Totals ignore shipping (see $shippingCents). */
final class CartLine
{
    /**
     * @param  array<int, CartIssue>  $issues
     * @param  int|null  $shippingCents  null = not known yet (no address) or not shippable (see issues)
     * @param  int|null  $maxQuantity  units that can be bought (1 for digital items, stock for listings)
     * @param  FulfillmentQuote|null  $fulfillment  physical listings: where the line would be fulfilled from (own stock or a supplier)
     */
    public function __construct(
        public readonly CartItem $item,
        public readonly ?Purchasable $purchasable,
        public readonly string $title,
        public readonly ?User $seller,
        public readonly int $quantity,
        public readonly int $unitPriceCents,
        public readonly int $storedUnitPriceCents,
        public readonly bool $isDigital,
        public readonly ?int $shippingCents,
        public readonly ?int $maxQuantity,
        public readonly array $issues = [],
        public readonly ?FulfillmentQuote $fulfillment = null,
    ) {
    }

    /** Where this line comes from: the supplier when checkout would dropship it, otherwise own stock. */
    public function fulfillmentSource(): FulfillmentSource
    {
        return $this->fulfillment?->source ?? FulfillmentSource::STOCK;
    }

    public function isDropshipped(): bool
    {
        return ! $this->isDigital && $this->fulfillmentSource() === FulfillmentSource::SUPPLIER;
    }

    /** The shipping group the line is in within its seller: "stock" or "supplier:{id}". */
    public function shippingGroupKey(): string
    {
        return $this->isDropshipped() && $this->fulfillment?->supplier !== null
            ? 'supplier:'.$this->fulfillment->supplier->getKey()
            : 'stock';
    }

    public function id(): int
    {
        return (int) $this->item->getKey();
    }

    public function lineTotalCents(): int
    {
        return $this->unitPriceCents * $this->quantity;
    }

    /** The price changed since the item was put in the cart. */
    public function priceChanged(): bool
    {
        return $this->unitPriceCents !== $this->storedUnitPriceCents;
    }

    public function hasIssues(): bool
    {
        return $this->issues !== [];
    }

    public function hasIssue(CartIssueType $type): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->type === $type) {
                return true;
            }
        }

        return false;
    }
}
