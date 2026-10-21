<?php

namespace App\Services\Marketplace;

use App\Models\User;

/**
 * Platform commission per seller order (docs/PLAN.md 3.7).
 *
 *   fee    = round_half_up(items subtotal x bps / 10000) + fee_fixed_cents   (never on shipping)
 *   payout = subtotal + shipping - fee
 *
 * The seller's SellerProfile::$commission_bps overrides marketplace.fee_bps. Platform sellers
 * (admins) keep the whole revenue on the platform: fee 0 and payout 0, no transfer is made.
 */
class FeeCalculator
{
    /** Commission rate in basis points for this seller (100 bps = 1 %). */
    public function bpsFor(?User $seller): int
    {
        $override = $seller?->sellerProfile?->commission_bps;

        return max(0, (int) ($override ?? config('marketplace.fee_bps', 1000)));
    }

    /** The platform fee for $itemsSubtotalCents of items sold by $seller. */
    public function feeCents(int $itemsSubtotalCents, ?User $seller): int
    {
        if ($itemsSubtotalCents <= 0 || ($seller !== null && $seller->isPlatformSeller())) {
            return 0;
        }

        // Integer round-half-up of subtotal * bps / 10000.
        $percentage = intdiv($itemsSubtotalCents * $this->bpsFor($seller) + 5000, 10000);
        $fee = $percentage + max(0, (int) config('marketplace.fee_fixed_cents', 0));

        // The fee can never exceed the items it is charged on.
        return min($fee, $itemsSubtotalCents);
    }

    /** What the seller receives: items plus shipping minus the fee (0 for platform sellers). */
    public function payoutCents(int $itemsSubtotalCents, int $shippingCents, ?User $seller): int
    {
        if ($seller !== null && $seller->isPlatformSeller()) {
            return 0;
        }

        return max(0, $itemsSubtotalCents + $shippingCents - $this->feeCents($itemsSubtotalCents, $seller));
    }
}
