<?php

namespace App\Services\Marketplace\Data;

use App\Models\Order;
use App\Models\SellerOrder;

/**
 * What a refund did. $reversalFailed lists seller orders whose payout could not be taken back from
 * the seller (the buyer was refunded anyway): retry with RefundService::retryTransferReversal().
 */
final class RefundResult
{
    /**
     * @param  array<int, SellerOrder>  $sellerOrders  the seller orders refunded now
     * @param  array<int, SellerOrder>  $reversalFailed
     */
    public function __construct(
        public readonly Order $order,
        public readonly array $sellerOrders,
        public readonly int $amountCents,
        public readonly ?string $refundId,
        public readonly array $reversalFailed = [],
    ) {
    }

    public function hasFailedReversals(): bool
    {
        return $this->reversalFailed !== [];
    }
}
