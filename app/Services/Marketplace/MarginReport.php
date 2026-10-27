<?php

namespace App\Services\Marketplace;

use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Enums\SupplierOrderStatus;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use App\Models\User;
use App\Services\Marketplace\Data\MarginRow;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What the SDLabs store earns (docs/PLAN.md 8.4). For paid orders in a date range, the part of each order that belongs to
 * the platform seller (the admins): revenue (items + shipping charged), refunds, costs (unit cost x quantity of stock and
 * supplier lines - the cost snapshot on the order item, the listing's current cost for rows from before R3 -, what
 * suppliers charge for shipping, dropship fees), an estimate of Stripe's fee (marketplace.stripe_fee_bps +
 * stripe_fee_fixed_cents per order) and the margin left. Lines cancelled on a purchase order cost nothing; supplier
 * shipping that is not entered yet counts as 0 and marks the row costsPending. Marketplace sellers' orders are not
 * part of it (the platform's income from them is its fee).
 *
 * Also the unit-margin helpers the store listing form uses: unitMargin() and lowMarginWarning().
 */
class MarginReport
{
    /**
     * @return Collection<int, MarginRow> one per paid order that has a platform seller part, oldest first
     */
    public function rows(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $adminIds = User::role('admin')->pluck('id')->all();

        if ($adminIds === []) {
            return collect();
        }

        $orders = Order::query()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereIn('status', [OrderStatus::PAID->value, OrderStatus::COMPLETED->value, OrderStatus::REFUNDED->value, OrderStatus::PARTIALLY_REFUNDED->value])
            ->whereHas('sellerOrders', fn ($q) => $q->whereIn('seller_id', $adminIds)->whereNotIn('status', [SellerOrderStatus::CANCELLED->value, SellerOrderStatus::PENDING->value]))
            ->with(['sellerOrders' => fn ($q) => $q->whereIn('seller_id', $adminIds)->whereNotIn('status', [SellerOrderStatus::CANCELLED->value, SellerOrderStatus::PENDING->value]),
                'sellerOrders.items.supplierOrderItem', 'sellerOrders.supplierOrders'])
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();

        // Cost of rows from before R3 (no snapshot): the listing's current cost.
        $listingIds = $orders->flatMap(fn (Order $order) => $order->sellerOrders)->flatMap(fn (SellerOrder $so) => $so->items)
            ->filter(fn (OrderItem $item) => ! $item->is_digital && $item->unit_cost_cents === null && $item->purchasable_type === (new Listing)->getMorphClass())
            ->pluck('purchasable_id')->unique()->all();
        $currentCosts = Listing::withTrashed()->whereIn('id', $listingIds)->pluck('cost_cents', 'id');

        return $orders->map(fn (Order $order) => $this->row($order, $currentCosts))->values();
    }

    /** Rows plus the totals row. @return array{rows: Collection<int, MarginRow>, totals: MarginRow} */
    public function forRange(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->rows($from, $to);

        return ['rows' => $rows, 'totals' => MarginRow::total($rows)];
    }

    /** @param  Collection<int, int|null>  $currentCosts  listing id => current cost_cents */
    private function row(Order $order, Collection $currentCosts): MarginRow
    {
        $items = $shipping = $refunds = $goods = $supplierShipping = $fees = 0;
        $pending = false;

        foreach ($order->sellerOrders as $sellerOrder) {
            $items += $sellerOrder->subtotal_cents;
            $shipping += $sellerOrder->shipping_cents;
            $refunds += $sellerOrder->refunded_cents;

            foreach ($sellerOrder->items as $item) {
                if ($item->is_digital || $item->supplierOrderItem?->isCancelled()) {
                    continue;
                }

                $unit = $item->unit_cost_cents ?? $currentCosts->get($item->purchasable_id);
                if ($unit === null) {
                    $pending = true;

                    continue;
                }

                $goods += $unit * $item->quantity;
            }

            foreach ($sellerOrder->supplierOrders as $po) {
                if ($po->status === SupplierOrderStatus::CANCELLED) {
                    continue;
                }

                $supplierShipping += (int) $po->shipping_cost_cents;
                $fees += $po->dropship_fee_cents;
                $pending = $pending || $po->shipping_cost_cents === null;
            }
        }

        [$stripeFee, $estimated] = $this->stripeFee($order, $items + $shipping);

        return new MarginRow(
            orderNumber: $order->number,
            paidAt: $order->paid_at,
            itemsCents: $items,
            shippingCents: $shipping,
            refundsCents: $refunds,
            goodsCostCents: $goods,
            supplierShippingCents: $supplierShipping,
            dropshipFeesCents: $fees,
            stripeFeeCents: $stripeFee,
            costsPending: $pending,
            estimatedFees: $estimated ? 1 : 0,
        );
    }

    /**
     * The Stripe fee of the part of the order that is worth $partCents (items + shipping): what Stripe charged, shared by value when
     * the order has other sellers' parts, or the estimate when the fee is not known yet.
     *
     * @return array{0: int, 1: bool} cents, and whether it is an estimate
     */
    public function stripeFee(Order $order, int $partCents): array
    {
        if ($order->stripe_fee_cents === null) {
            return [$this->stripeFeeCents($partCents), true];
        }

        $whole = $order->subtotal_cents + $order->shipping_cents;

        if ($whole <= 0 || $partCents >= $whole) {
            return [$order->stripe_fee_cents, false];
        }

        // rounded half up
        return [intdiv($order->stripe_fee_cents * $partCents * 2 + $whole, 2 * $whole), false];
    }

    /** Stripe's fee for charging $revenueCents: revenue x stripe_fee_bps (rounded half up) + stripe_fee_fixed_cents. */
    public function stripeFeeCents(int $revenueCents): int
    {
        if ($revenueCents <= 0) {
            return 0;
        }

        return intdiv($revenueCents * max(0, (int) config('marketplace.stripe_fee_bps', 290)) + 5000, 10000)
            + max(0, (int) config('marketplace.stripe_fee_fixed_cents', 30));
    }

    /** The report as CSV (money in dollars with two decimals), one row per order and a totals row. */
    public function toCsv(CarbonInterface $from, CarbonInterface $to): string
    {
        $report = $this->forRange($from, $to);
        $money = fn (int $cents): string => number_format($cents / 100, 2, '.', '');

        // The header says "(est.)" as soon as one order's fee is an estimate; the last column says which ones.
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Order', 'Paid', 'Items', 'Shipping charged', 'Revenue', 'Refunds', 'Goods cost', 'Supplier shipping', 'Dropship fees', $report['totals']->stripeFeeIsEstimate() ? 'Stripe fee (est.)' : 'Stripe fee', 'Margin', 'Margin %', 'Costs pending', 'Stripe fee estimated'], escape: '');

        $line = fn (MarginRow $row, string $label, string $paid): array => [
            $label,
            $paid,
            $money($row->itemsCents),
            $money($row->shippingCents),
            $money($row->revenueCents()),
            $money($row->refundsCents),
            $money($row->goodsCostCents),
            $money($row->supplierShippingCents),
            $money($row->dropshipFeesCents),
            $money($row->stripeFeeCents),
            $money($row->marginCents()),
            $row->marginPercent() === null ? '' : number_format($row->marginPercent(), 1, '.', ''),
            $row->costsPending ? 'yes' : '',
            $row->stripeFeeIsEstimate() ? 'yes' : '',
        ];

        foreach ($report['rows'] as $row) {
            fputcsv($handle, $line($row, (string) $row->orderNumber, $row->paidAt?->format('Y-m-d') ?? ''), escape: '');
        }

        fputcsv($handle, $line($report['totals'], 'Total ('.$report['totals']->orders.' orders)', ''), escape: '');

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    // --- Unit margin of a store listing -----------------------------------------------------------------------------

    /**
     * Margin of one unit sold at $priceCents that costs $costCents: cents and basis points of the price (before shipping
     * and Stripe's fee). Null when the cost is unknown or the price is not positive.
     *
     * @return array{cents: int, bps: int}|null
     */
    public static function unitMargin(int $priceCents, ?int $costCents): ?array
    {
        if ($costCents === null || $priceCents <= 0) {
            return null;
        }

        $cents = $priceCents - $costCents;

        return ['cents' => $cents, 'bps' => (int) round($cents * 10000 / $priceCents)];
    }

    /** The warning for a store listing form: the margin is negative or below marketplace.min_margin_bps. Null when fine or unknown. */
    public static function lowMarginWarning(int $priceCents, ?int $costCents): ?string
    {
        $margin = static::unitMargin($priceCents, $costCents);

        if ($margin === null) {
            return null;
        }

        $minimum = max(0, (int) config('marketplace.min_margin_bps', 1500));
        $percent = number_format($margin['bps'] / 100, 1);

        return match (true) {
            $margin['cents'] < 0 => "Sold at a loss: {$percent} % margin (".money($margin['cents']).' per unit).',
            $margin['bps'] < $minimum => "Low margin: {$percent} % (".money($margin['cents']).' per unit); the minimum is '.number_format($minimum / 100, 1).' %.',
            default => null,
        };
    }
}
