<?php

namespace Database\Factories\Support;

use App\Contracts\Purchasable;
use App\Enums\EntitlementSource;
use App\Enums\SellerOrderStatus;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Fills an existing Order with seller orders, order items and entitlements in a consistent state
 * (totals, fee, payout, tracking, transfers). Used by OrderFactory and by the demo seeder; it does
 * not need Faker, except for the default item (a Listing factory) when no items are passed.
 *
 * $stage: pending | paid | shipped | completed | cancelled | refunded.
 * Money semantics: order_items.line_total_cents = unit price x quantity (shipping is separate);
 * seller_orders.subtotal_cents = sum of its items; fee = bps of the subtotal (not of shipping);
 * payout = subtotal + shipping - fee; platform sellers (admins) get fee 0 and payout 0.
 */
final class OrderBuilder
{
    /**
     * @param  array<int, Purchasable|array{0: Purchasable, 1: int}>  $items
     */
    public static function build(Order $order, array $items, string $stage): void
    {
        $buyer = $order->user;

        if ($items === []) {
            $items = [Listing::factory()->create()];
        }

        $country = strtoupper((string) ($order->shipping_address['country'] ?? 'US'));
        $bySeller = [];
        foreach ($items as $entry) {
            [$purchasable, $quantity] = is_array($entry) ? [$entry[0], $entry[1] ?? 1] : [$entry, 1];
            /** @var Model&Purchasable $purchasable */
            $bySeller[$purchasable->getSeller()?->getKey()][] = [$purchasable, $purchasable->isDigital() ? 1 : max(1, (int) $quantity)];
        }

        $totals = ['subtotal' => 0, 'shipping' => 0, 'fee' => 0];

        foreach ($bySeller as $sellerId => $lines) {
            $seller = User::findOrFail($sellerId);
            $subtotal = 0;
            $shipping = 0;
            $hasPhysical = false;
            $created = [];

            $sellerOrder = SellerOrder::create(['order_id' => $order->getKey(), 'seller_id' => $seller->getKey(), 'status' => SellerOrderStatus::PENDING]);

            foreach ($lines as [$purchasable, $quantity]) {
                $unit = $purchasable->purchasablePriceCents();
                $lineShipping = $purchasable instanceof Listing ? ($purchasable->shippingCentsFor($country, $quantity) ?? 0) : 0;
                $hasPhysical = $hasPhysical || ! $purchasable->isDigital();
                $subtotal += $unit * $quantity;
                $shipping += $lineShipping;

                $created[] = [OrderItem::create([
                    'order_id' => $order->getKey(),
                    'seller_order_id' => $sellerOrder->getKey(),
                    'purchasable_type' => $purchasable->getMorphClass(),
                    'purchasable_id' => $purchasable->getKey(),
                    'title' => $purchasable->purchasableTitle(),
                    'is_digital' => $purchasable->isDigital(),
                    'quantity' => $quantity,
                    'unit_price_cents' => $unit,
                    'shipping_cents' => $lineShipping,
                    'line_total_cents' => $unit * $quantity,
                ]), $purchasable];
            }

            $platform = $seller->isPlatformSeller();
            $bps = $seller->sellerProfile?->feeBps() ?? (int) config('marketplace.fee_bps', 1000);
            $fee = $platform ? 0 : (int) round($subtotal * $bps / 10000) + (int) config('marketplace.fee_fixed_cents', 0);
            $payout = $platform ? 0 : $subtotal + $shipping - $fee;

            [$status, $transferred, $tracking] = match (true) {
                $stage === 'pending' => [SellerOrderStatus::PENDING, false, false],
                $stage === 'cancelled' => [SellerOrderStatus::CANCELLED, false, false],
                $stage === 'refunded' => [SellerOrderStatus::REFUNDED, false, false],
                ! $hasPhysical => [SellerOrderStatus::COMPLETED, true, false],
                $stage === 'paid' => [SellerOrderStatus::AWAITING_SHIPMENT, config('marketplace.physical_payout_trigger') === 'paid', false],
                $stage === 'shipped' => [SellerOrderStatus::SHIPPED, true, true],
                default => [SellerOrderStatus::COMPLETED, true, true],
            };

            $sellerOrder->update([
                'status' => $status,
                'subtotal_cents' => $subtotal,
                'shipping_cents' => $shipping,
                'platform_fee_cents' => $fee,
                'payout_cents' => $payout,
                'stripe_transfer_id' => $transferred && ! $platform ? 'tr_'.Str::random(20) : null,
                'transferred_at' => $transferred && ! $platform ? now() : null,
                'carrier' => $tracking ? 'USPS' : null,
                'tracking_number' => $tracking ? '9400'.random_int(100000000000, 999999999999) : null,
                'tracking_url' => null,
                'shipped_at' => $tracking ? now()->subDay() : null,
                'delivered_at' => $stage === 'completed' && $hasPhysical ? now() : null,
            ]);

            if (in_array($stage, ['paid', 'shipped', 'completed', 'refunded'], true)) {
                foreach ($created as [$item, $purchasable]) {
                    if ($purchasable->isDigital()) {
                        $entitlement = $buyer->grantEntitlement($purchasable, EntitlementSource::PURCHASE, $item);
                        if ($stage === 'refunded') {
                            $entitlement->revoke();
                        }
                    }
                }
            }

            $totals['subtotal'] += $subtotal;
            $totals['shipping'] += $shipping;
            $totals['fee'] += $fee;
        }

        $order->update([
            'subtotal_cents' => $totals['subtotal'],
            'shipping_cents' => $totals['shipping'],
            'platform_fee_cents' => $totals['fee'],
            'total_cents' => $totals['subtotal'] + $totals['shipping'] + $order->tax_cents,
        ]);
    }
}
