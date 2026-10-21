<?php

namespace App\Exceptions\Marketplace;

use App\Services\Marketplace\Data\CartIssue;
use RuntimeException;
use Throwable;

/**
 * Checkout was refused. $error says why (typed, switch on it); getMessage() is safe to show to the
 * buyer; $issues lists the offending cart lines for the UNAVAILABLE_ITEMS and NOT_SHIPPABLE cases.
 */
class CheckoutException extends RuntimeException
{
    /** @param  array<int, CartIssue>  $issues */
    public function __construct(
        public readonly CheckoutError $error,
        public readonly array $issues = [],
        ?string $message = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message ?? $error->message(), 0, $previous);
    }

    /** @return array<int, string> */
    public function issueMessages(): array
    {
        return array_map(fn (CartIssue $issue) => $issue->message, $this->issues);
    }
}
