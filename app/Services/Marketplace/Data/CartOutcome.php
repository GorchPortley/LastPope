<?php

namespace App\Services\Marketplace\Data;

/**
 * What a CartService action did. Turn it into a flash message with CartResult::message() and colour
 * it by level(): "success", "info" (nothing to do) or "error".
 */
enum CartOutcome: string
{
    case ADDED = 'added';
    case QUANTITY_UPDATED = 'quantity_updated';
    case UNCHANGED = 'unchanged';
    case FREE_GRANTED = 'free_granted';
    case REMOVED = 'removed';
    case CLEARED = 'cleared';

    case ALREADY_IN_CART = 'already_in_cart';
    case ALREADY_OWNED = 'already_owned';

    case OWN_ITEM = 'own_item';
    case NOT_AVAILABLE = 'not_available';
    case INSUFFICIENT_STOCK = 'insufficient_stock';
    case INVALID_QUANTITY = 'invalid_quantity';
    case ACCESS_REVOKED = 'access_revoked';
    case ITEM_NOT_FOUND = 'item_not_found';

    /** @return 'success'|'info'|'error' */
    public function level(): string
    {
        return match ($this) {
            self::ADDED, self::QUANTITY_UPDATED, self::FREE_GRANTED, self::REMOVED, self::CLEARED => 'success',
            self::UNCHANGED, self::ALREADY_IN_CART, self::ALREADY_OWNED => 'info',
            default => 'error',
        };
    }

    public function message(?string $title = null, ?int $available = null): string
    {
        $item = $title ?: 'This item';

        return match ($this) {
            self::ADDED => "{$item} was added to your cart.",
            self::QUANTITY_UPDATED => 'Your cart was updated.',
            self::UNCHANGED => 'Your cart is unchanged.',
            self::FREE_GRANTED => "{$item} is free and was added to your library.",
            self::REMOVED => "{$item} was removed from your cart.",
            self::CLEARED => 'Your cart is empty.',
            self::ALREADY_IN_CART => "{$item} is already in your cart.",
            self::ALREADY_OWNED => "You already have access to {$item}.",
            self::OWN_ITEM => 'You cannot buy your own items.',
            self::NOT_AVAILABLE => "{$item} is not available right now.",
            self::INSUFFICIENT_STOCK => ($available ?? 0) > 0
                ? "Only {$available} available of {$item}."
                : "{$item} is sold out or on hold for another buyer.",
            self::INVALID_QUANTITY => 'Choose a quantity of at least 1.',
            self::ACCESS_REVOKED => "Your access to {$item} was withdrawn. Contact support if this is a mistake.",
            self::ITEM_NOT_FOUND => 'That item is not in your cart.',
        };
    }
}
