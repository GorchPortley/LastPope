<?php

namespace App\Services\Marketplace\Data;

/** A problem with one cart line. The UI shows $message and offers to remove or fix the line. */
final class CartIssue
{
    public function __construct(
        public readonly CartIssueType $type,
        public readonly string $message,
        public readonly ?int $cartItemId = null,
        public readonly ?int $available = null,
    ) {
    }
}
