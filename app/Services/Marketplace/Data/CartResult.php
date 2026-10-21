<?php

namespace App\Services\Marketplace\Data;

use App\Models\CartItem;
use App\Models\Entitlement;

/**
 * The result of a CartService action.
 *
 *     $result = $carts->add($user, $design);
 *     session()->flash($result->level(), $result->message());   // "success" | "info" | "error"
 */
final class CartResult
{
    public function __construct(
        public readonly CartOutcome $outcome,
        public readonly ?CartItem $item = null,
        public readonly ?Entitlement $entitlement = null,
        public readonly ?string $title = null,
        public readonly ?int $available = null,
    ) {
    }

    /** The action did what was asked (added, updated, removed, free item granted...). */
    public function ok(): bool
    {
        return $this->outcome->level() === 'success';
    }

    /** @return 'success'|'info'|'error' */
    public function level(): string
    {
        return $this->outcome->level();
    }

    public function message(): string
    {
        return $this->outcome->message($this->title, $this->available);
    }
}
