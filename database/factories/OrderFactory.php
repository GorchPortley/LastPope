<?php

namespace Database\Factories;

use Database\Factories\Support\OrderBuilder;
use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
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
        ])->afterCreating(fn (Order $order) => OrderBuilder::build($order, $items, 'pending'));
    }

    /** Paid: digital items fulfilled, physical ones awaiting shipment. */
    public function paid(array $items = []): static
    {
        return $this->state(fn () => $this->paidAttributes(OrderStatus::PAID))
            ->afterCreating(fn (Order $order) => OrderBuilder::build($order, $items, 'paid'));
    }

    /** Paid and every physical seller order shipped with tracking. */
    public function shipped(array $items = []): static
    {
        return $this->state(fn () => $this->paidAttributes(OrderStatus::PAID))
            ->afterCreating(fn (Order $order) => OrderBuilder::build($order, $items, 'shipped'));
    }

    /** Delivered/confirmed: everything completed. */
    public function completed(array $items = []): static
    {
        return $this->state(fn () => $this->paidAttributes(OrderStatus::COMPLETED))
            ->afterCreating(fn (Order $order) => OrderBuilder::build($order, $items, 'completed'));
    }

    public function cancelled(array $items = []): static
    {
        return $this->state(fn () => ['status' => OrderStatus::CANCELLED, 'stripe_checkout_session_id' => 'cs_test_'.Str::random(24)])
            ->afterCreating(fn (Order $order) => OrderBuilder::build($order, $items, 'cancelled'));
    }

    /** Refunded in full: seller orders refunded, entitlements revoked. */
    public function refunded(array $items = []): static
    {
        return $this->state(fn () => $this->paidAttributes(OrderStatus::REFUNDED))
            ->afterCreating(fn (Order $order) => OrderBuilder::build($order, $items, 'refunded'));
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
}
