<?php

namespace App\Services\Marketplace\Data;

use Illuminate\Support\Carbon;

/**
 * The numbers of one paid store order - or, as the totals row, of many - in the margin report (docs/PLAN.md 8.4).
 * All money is integer cents.
 *
 *   revenue    items + shipping charged for the platform's part of the order
 *   refunds    given back to the buyers
 *   costs      goods (unit cost x quantity, stock and supplier lines alike), supplier shipping, dropship fees
 *   stripeFee  an estimate: revenue x stripe_fee_bps + stripe_fee_fixed_cents per order (Stripe keeps its fee on refunds)
 *   margin     revenue - refunds - costs - stripeFee
 */
final class MarginRow
{
    public function __construct(
        public readonly ?string $orderNumber,
        public readonly ?Carbon $paidAt,
        public readonly int $itemsCents,
        public readonly int $shippingCents,
        public readonly int $refundsCents,
        public readonly int $goodsCostCents,
        public readonly int $supplierShippingCents,
        public readonly int $dropshipFeesCents,
        public readonly int $stripeFeeCents,
        public readonly bool $costsPending = false,
        public readonly int $orders = 1,
    ) {
    }

    /** Items plus shipping charged. */
    public function revenueCents(): int
    {
        return $this->itemsCents + $this->shippingCents;
    }

    /** What the buyers paid and kept: revenue minus refunds. */
    public function netRevenueCents(): int
    {
        return $this->revenueCents() - $this->refundsCents;
    }

    public function costsCents(): int
    {
        return $this->goodsCostCents + $this->supplierShippingCents + $this->dropshipFeesCents;
    }

    public function marginCents(): int
    {
        return $this->netRevenueCents() - $this->costsCents() - $this->stripeFeeCents;
    }

    /** The margin as basis points of the net revenue (1500 = 15 %), null when nothing was kept. */
    public function marginBps(): ?int
    {
        return $this->netRevenueCents() > 0
            ? (int) round($this->marginCents() * 10000 / $this->netRevenueCents())
            : null;
    }

    /** The margin as a percentage rounded to one decimal ("15.2"), or null. */
    public function marginPercent(): ?float
    {
        $bps = $this->marginBps();

        return $bps === null ? null : round($bps / 100, 1);
    }

    /**
     * The totals row of many rows.
     *
     * @param  iterable<int, MarginRow>  $rows
     */
    public static function total(iterable $rows): self
    {
        $items = $shipping = $refunds = $goods = $supplierShipping = $fees = $stripe = $count = 0;
        $pending = false;

        foreach ($rows as $row) {
            $items += $row->itemsCents;
            $shipping += $row->shippingCents;
            $refunds += $row->refundsCents;
            $goods += $row->goodsCostCents;
            $supplierShipping += $row->supplierShippingCents;
            $fees += $row->dropshipFeesCents;
            $stripe += $row->stripeFeeCents;
            $pending = $pending || $row->costsPending;
            $count += $row->orders;
        }

        return new self(null, null, $items, $shipping, $refunds, $goods, $supplierShipping, $fees, $stripe, $pending, $count);
    }
}
