<?php

namespace App\Services\Marketplace\Data;

use App\Models\Address;

/**
 * The cart as the buyer would pay for it today: lines grouped by seller, shipping to $address,
 * platform fee per seller, and everything that blocks checkout.
 *
 * Money is integer cents. Tax is not included: with Stripe Tax it is added on the Stripe page.
 * When a cart has physical items and no address was given, shipping is "pending" and totalCents()
 * only counts what is known so far.
 */
final class CartSummary
{
    /**
     * @param  array<int, SellerGroup>  $sellers
     * @param  array<int, CartLine>  $lines  every line, in cart order
     */
    public function __construct(
        public readonly array $sellers,
        public readonly array $lines,
        public readonly ?Address $address,
        public readonly string $currency,
        public readonly int $itemCount,
        public readonly int $subtotalCents,
        public readonly int $shippingCents,
        public readonly int $feeCents,
        public readonly bool $needsAddress,
        public readonly bool $shippingPending,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /** Items plus shipping (before tax). */
    public function totalCents(): int
    {
        return $this->subtotalCents + $this->shippingCents;
    }

    /** @return array<int, CartIssue> every line problem, flattened */
    public function issues(): array
    {
        $issues = [];
        foreach ($this->lines as $line) {
            foreach ($line->issues as $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    public function hasIssues(): bool
    {
        return $this->issues() !== [];
    }

    /** Physical items are in the cart but no address was chosen yet. */
    public function addressMissing(): bool
    {
        return $this->needsAddress && $this->address === null;
    }

    /** Nothing blocks CheckoutService::start() with this address. */
    public function canCheckout(): bool
    {
        return ! $this->isEmpty() && ! $this->hasIssues() && ! $this->addressMissing();
    }

    /** @return array<int, string> the messages of all line problems */
    public function errors(): array
    {
        return array_map(fn (CartIssue $issue) => $issue->message, $this->issues());
    }
}
