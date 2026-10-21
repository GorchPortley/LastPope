<?php

namespace App\Exceptions\Marketplace;

/** Why CheckoutService::start() refused to start a payment. */
enum CheckoutError: string
{
    case EMPTY_CART = 'empty_cart';
    /** Physical items in the cart and no address was given. */
    case ADDRESS_REQUIRED = 'address_required';
    /** The address belongs to somebody else or is incomplete. */
    case ADDRESS_INVALID = 'address_invalid';
    /** A seller does not ship to the address' country. */
    case NOT_SHIPPABLE = 'not_shippable';
    /** Unavailable, sold out, own or already-owned items: see CheckoutException::$issues. */
    case UNAVAILABLE_ITEMS = 'unavailable_items';
    /** The total differs from the one the buyer saw ($expectedTotalCents). */
    case PRICE_CHANGED = 'price_changed';
    /** Stripe could not create the payment page. */
    case GATEWAY_ERROR = 'gateway_error';

    public function message(): string
    {
        return match ($this) {
            self::EMPTY_CART => 'Your cart is empty.',
            self::ADDRESS_REQUIRED => 'Choose a shipping address to continue.',
            self::ADDRESS_INVALID => 'That shipping address cannot be used. Check it and try again.',
            self::NOT_SHIPPABLE => 'A seller in your cart does not ship to that address.',
            self::UNAVAILABLE_ITEMS => 'Some items in your cart are no longer available.',
            self::PRICE_CHANGED => 'Prices changed while you were checking out. Review your cart and try again.',
            self::GATEWAY_ERROR => 'We could not start the payment. Please try again in a moment.',
        };
    }
}
