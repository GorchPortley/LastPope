<?php

namespace App\Services\Marketplace\Data;

/**
 * One parcel of a seller's part of the cart (docs/PLAN.md 8.2): the lines that ship together and what shipping costs for
 * them. A marketplace seller has one group; the SDLabs store has one for its own stock and one per supplier.
 * The label never names the supplier: "Ships from SDLabs" / "Ships separately (5–8 business days)".
 */
final class ShippingGroup
{
    public const KIND_SELLER = 'seller';

    public const KIND_STOCK = 'stock';

    public const KIND_SUPPLIER = 'supplier';

    /**
     * @param  string  $key  "seller", "stock" or "supplier:{id}" (unique within a seller)
     * @param  'seller'|'stock'|'supplier'  $kind
     * @param  string  $name  "Ships from SDLabs" or "Ships separately"
     * @param  string  $detail  the supplier lead time ("5–8 business days"), "" otherwise
     * @param  array<int, CartLine>  $lines
     * @param  bool  $shippingPending  physical lines but no address yet
     */
    public function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly string $name,
        public readonly string $detail,
        public readonly array $lines,
        public readonly int $subtotalCents,
        public readonly int $shippingCents,
        public readonly bool $shippingPending,
        public readonly ?int $supplierId = null,
        public readonly ?int $leadTimeMinDays = null,
        public readonly ?int $leadTimeMaxDays = null,
    ) {
    }

    public function isSupplier(): bool
    {
        return $this->kind === self::KIND_SUPPLIER;
    }

    public function unitCount(): int
    {
        return array_sum(array_map(fn (CartLine $line) => $line->quantity, $this->lines));
    }

    /** "Ships from SDLabs" or "Ships separately (5–8 business days)". */
    public function label(): string
    {
        return $this->detail === '' ? $this->name : $this->name.' ('.$this->detail.')';
    }

    /** The name of the shipping line on the Stripe page: "Shipping (ships separately, 5–8 business days)". */
    public function shippingLabel(): string
    {
        return 'Shipping ('.lcfirst($this->name).($this->detail === '' ? '' : ', '.$this->detail).')';
    }
}
