<?php

namespace Database\Factories\Support;

use App\Contracts\Purchasable;
use App\Enums\EntitlementSource;
use App\Enums\FulfillmentSource;
use App\Enums\SupplierOrderStatus;
use App\Enums\SellerOrderStatus;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Marketplace\PurchaseOrderService;
use App\Services\Marketplace\ShippingCalculator;
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
 *
 * Payout state follows marketplace.physical_payout_trigger: digital items are paid out at once; physical ones with
 * "paid" at once, with "shipped" when shipped, with "shipped_delay" (default) physical_payout_delay_days after the
 * parcel shipped. So a "shipped" order (shipped yesterday) is not paid out yet under shipped_delay, while a
 * "completed" one (shipped long enough ago) is. $options['shipped_days_ago'] sets when the parcel shipped.
 *
 * R3: physical listing lines get their fulfilment source (a dropshipped store listing is bought from its supplier,
 * Listing::fulfillmentFor()), a cost snapshot and shipping priced as at checkout (supplier lines as one parcel per
 * supplier). Every shipped seller order gets shipments - one per fulfilment source - and the paid, shipped and
 * completed stages get purchase orders for the supplier lines (to send when paid, shipped when the parcels are).
 */
final class OrderBuilder
{
    /**
     * @param  array<int, Purchasable|array{0: Purchasable, 1: int}>  $items
     * @param  array{shipped_days_ago?: int}  $options
     */
    public static function build(Order $order, array $items, string $stage, array $options = []): void
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
        /** @var array<int, array<string, Shipment>> $parcels seller order id => fulfilment key => shipment */
        $parcels = [];
        $trigger = (string) config('marketplace.physical_payout_trigger', 'shipped_delay');
        $delay = max(0, (int) config('marketplace.physical_payout_delay_days', 14));
        $shippedAt = now()->subDays($options['shipped_days_ago'] ?? ($stage === 'completed' ? ($trigger === 'shipped_delay' ? $delay + 2 : 2) : 1));
        $earliestShipment = null;

        foreach ($bySeller as $sellerId => $lines) {
            $seller = User::findOrFail($sellerId);
            $subtotal = 0;
            $shipping = 0;
            $hasPhysical = false;
            $created = [];

            $sellerOrder = SellerOrder::create(['order_id' => $order->getKey(), 'seller_id' => $seller->getKey(), 'status' => SellerOrderStatus::PENDING]);

            // Shipping as checkout prices it: per line for own stock, one parcel per supplier for dropshipped lines.
            $quote = app(ShippingCalculator::class)->quote(array_map(fn (array $line) => [$line[0], $line[1]], $lines), $country);

            foreach ($lines as $index => [$purchasable, $quantity]) {
                $unit = $purchasable->purchasablePriceCents();
                $lineShipping = $quote->lines[$index] ?? 0;
                $fulfillment = $purchasable instanceof Listing && ! $purchasable->isDigital() ? $purchasable->fulfillmentFor($quantity) : null;
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
                    'fulfillment_source' => $fulfillment?->source,
                    'supplier_id' => $fulfillment?->source === FulfillmentSource::SUPPLIER ? $fulfillment->supplier?->getKey() : null,
                    'quantity' => $quantity,
                    'unit_price_cents' => $unit,
                    'unit_cost_cents' => $purchasable instanceof Listing ? $purchasable->cost_cents : null,
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
                $stage === 'paid' => [SellerOrderStatus::AWAITING_SHIPMENT, $trigger === 'paid', false],
                $stage === 'shipped' => [SellerOrderStatus::SHIPPED, $trigger !== 'shipped_delay' || $shippedAt->copy()->addDays($delay)->lte(now()), true],
                default => [SellerOrderStatus::COMPLETED, $trigger !== 'shipped_delay' || $shippedAt->copy()->addDays($delay)->lte(now()), true],
            };

            // When the transfer happened: at payment / shipment, or physical_payout_delay_days after it.
            $transferredAt = match (true) {
                ! $transferred => null,
                $tracking && $trigger === 'shipped_delay' => $shippedAt->copy()->addDays($delay),
                $tracking => $shippedAt->copy(),
                default => now(),
            };

            // One parcel per fulfilment source; a store order with dropshipped lines ships in several, the last one a day later.
            $shippedAtOfSeller = $shippedAt;
            if ($tracking) {
                $groups = collect($created)->map(fn (array $pair) => $pair[0])->reject(fn (OrderItem $item) => $item->is_digital)
                    ->groupBy(fn (OrderItem $item) => $item->fulfilmentKey())
                    ->sortBy(fn ($items, string $key) => $key === 'stock' ? '' : $key);

                foreach ($groups->keys() as $position => $key) {
                    $at = $position === 0 ? $shippedAt : $shippedAt->copy()->addDays($position)->min(now()->subHour());
                    $shipment = Shipment::create([
                        'seller_order_id' => $sellerOrder->getKey(),
                        'carrier' => 'USPS',
                        'tracking_number' => '9400'.random_int(100000000000, 999999999999),
                        'tracking_url' => null,
                        'shipped_at' => $at,
                    ]);
                    foreach ($groups[$key] as $item) {
                        ShipmentItem::create(['shipment_id' => $shipment->getKey(), 'order_item_id' => $item->getKey(), 'quantity' => $item->quantity]);
                    }
                    $parcels[$sellerOrder->getKey()][$key] = $shipment;
                    $shippedAtOfSeller = $at->greaterThan($shippedAtOfSeller) ? $at : $shippedAtOfSeller;
                }

                $latest = collect($parcels[$sellerOrder->getKey()] ?? [])->sortBy(fn (Shipment $shipment) => $shipment->shipped_at)->last();
            }

            $sellerOrder->update([
                'status' => $status,
                'subtotal_cents' => $subtotal,
                'shipping_cents' => $shipping,
                'platform_fee_cents' => $fee,
                'payout_cents' => $payout,
                'stripe_transfer_id' => $transferred && ! $platform ? 'tr_'.Str::random(20) : null,
                'transferred_at' => $transferred && ! $platform ? $transferredAt : null,
                'carrier' => $tracking ? ($latest->carrier ?? 'USPS') : null,
                'tracking_number' => $tracking ? ($latest->tracking_number ?? '9400'.random_int(100000000000, 999999999999)) : null,
                'tracking_url' => null,
                'shipped_at' => $tracking ? $shippedAtOfSeller : null,
                'delivered_at' => $stage === 'completed' && $hasPhysical ? $shippedAt->copy()->addDay() : null,
            ]);

            if ($tracking) {
                $earliestShipment = $earliestShipment === null ? $shippedAt : $earliestShipment->min($shippedAt);
            }

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

        // An order cannot have been paid after its parcels shipped.
        if ($earliestShipment !== null && $order->paid_at !== null && $order->paid_at->gt($earliestShipment)) {
            $order->paid_at = $earliestShipment->copy()->subDay();
        }

        $order->update([
            'subtotal_cents' => $totals['subtotal'],
            'shipping_cents' => $totals['shipping'],
            'platform_fee_cents' => $totals['fee'],
            'total_cents' => $totals['subtotal'] + $totals['shipping'] + $order->tax_cents,
        ]);

        if (in_array($stage, ['paid', 'shipped', 'completed'], true)) {
            self::purchaseOrders($order->fresh(), $parcels);
        }
    }

    /**
     * The purchase orders of the dropshipped lines: waiting to be sent while the order is only paid; once its parcels
     * shipped they were sent, confirmed and shipped (the shipment of their lines points at them).
     *
     * @param  array<int, array<string, Shipment>>  $parcels
     */
    private static function purchaseOrders(Order $order, array $parcels): void
    {
        foreach (app(PurchaseOrderService::class)->createForOrder($order) as $po) {
            $shipment = $parcels[$po->seller_order_id]['supplier:'.$po->supplier_id] ?? null;

            if ($shipment === null) {
                continue;
            }

            $sentAt = $order->paid_at->copy()->addHour();
            $po->forceFill([
                'status' => SupplierOrderStatus::SHIPPED,
                'sent_at' => $sentAt,
                'confirmed_at' => $sentAt->copy()->addHours(3),
                'shipped_at' => $shipment->shipped_at,
                'supplier_reference' => 'SUP-'.random_int(10000, 99999),
                'shipping_cost_cents' => 650,
            ])->save();
            $shipment->forceFill(['supplier_order_id' => $po->getKey()])->save();
        }
    }
}
