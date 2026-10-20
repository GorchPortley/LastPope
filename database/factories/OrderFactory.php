<?php

namespace Database\Factories;

use App\Contracts\Purchasable;
use App\Enums\EntitlementSource;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Models\Address;
use App\Models\Entitlement;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Orders, optionally with their seller orders, order items and entitlements.
 *
 * Order::factory()->create() is a bare pending order. The state methods pending(), paid(),
 * shipped(), completed(), cancelled() and refunded() each take the purchasables to build the order
 * from (a Purchasable, or [Purchasable, quantity]) and create one SellerOrder per seller with
 * consistent totals, fee and payout. With no items they use one new active listing of a new
 * onboarded seller. Use only one of those states per factory chain.
 *
 *     Order::factory()->for($buyer, 'user')->paid([$design, [$listing, 2]])->create();
 *
 * Money semantics: order_items.line_total_cents = unit price x quantity (shipping is separate),
 * seller_orders.subtotal_cents = sum of its items, fee = bps of the subtotal (not of shipping),
 * payout = subtotal + shipping - fee; platform sellers (admins) get fee 0 and payout 0.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => OrderStatus::PENDING,
            'currency' => 'usd',
            'subtotal_cents' => 0,
            'shipping_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'platform_fee_cents' => 0,
            'shipping_address' => null,
            'stripe_checkout_session_id' => null,
            'stripe_payment_intent_id' => null,
            'stripe_charge_id' => null,
            'paid_at' => null,
        ];
    }

    /** Awaiting payment: a Checkout Session exists, nothing fulfilled. */
    public function pending(array $items = []): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::PENDING,
            'stripe_checkout_session_id' => 'cs_test_'.Str::random(24),
        ])->afterCreating(fn (Order $order) => $this->buildLines($order, $items, 'pending'));
    }

    /** Paid: digital items fulfilled, physical ones awaiting shipment. */
    public function paid(array $items = []): static
    {
        return $this->state(fn () => $this->paidAttributes(OrderStatus::PAID))
            ->afterCreating(fn (Order $order) => $this->buildLines($order, $items, 'paid'));
    }

    /** Paid and every physical seller order shipped with tracking. */
    public function shipped(array $items = []): static
    {
        return $this->state(fn () => $this->paidAttributes(OrderStatus::PAID))
            ->afterCreating(fn (Order $order) => $this->buildLines($order, $items, 'shipped'));
    }

    /** Delivered/confirmed: everything completed. */
    public function completed(array $items = []): static
    {
        return $this->state(fn () => $this->paidAttributes(OrderStatus::COMPLETED))
            ->afterCreating(fn (Order $order) => $this->buildLines($order, $items, 'completed'));
    }

    public function cancelled(array $items = []): static
    {
        return $this->state(fn () => ['status' => OrderStatus::CANCELLED, 'stripe_checkout_session_id' => 'cs_test_'.Str::random(24)])
            ->afterCreating(fn (Order $order) => $this->buildLines($order, $items, 'cancelled'));
    }

    /** Refunded in full: seller orders refunded, entitlements revoked. */
    public function refunded(array $items = []): static
    {
        return $this->state(fn () => $this->paidAttributes(OrderStatus::REFUNDED))
            ->afterCreating(fn (Order $order) => $this->buildLines($order, $items, 'refunded'));
    }

    /** Ship to a (saved or fresh) US address. */
    public function withShippingAddress(?Address $address = null): static
    {
        return $this->state(fn () => [
            'shipping_address' => ($address ?? Address::factory()->make())->toSnapshot(),
        ]);
    }

    /** @return array<string, mixed> */
    private function paidAttributes(OrderStatus $status): array
    {
        return [
            'status' => $status,
            'paid_at' => now()->subHours(2),
            'stripe_checkout_session_id' => 'cs_test_'.Str::random(24),
            'stripe_payment_intent_id' => 'pi_'.Str::random(24),
            'stripe_charge_id' => 'ch_'.Str::random(24),
        ];
    }

    /**
     * @param  array<int, Purchasable|array{0: Purchasable, 1: int}>  $items
     */
    private function buildLines(Order $order, array $items, string $stage): void
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
