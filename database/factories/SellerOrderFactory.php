<?php

namespace Database\Factories;

use App\Enums\SellerOrderStatus;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A bare seller order (no items). For consistent orders with items use Order::factory()->paid()...
 *
 * @extends Factory<SellerOrder>
 */
class SellerOrderFactory extends Factory
{
    public function definition(): array
    {
        $subtotal = $this->faker->numberBetween(2000, 40000);
        $shipping = 899;
        $fee = (int) round($subtotal * config('marketplace.fee_bps', 1000) / 10000);

        return [
            'order_id' => Order::factory(),
            'seller_id' => User::factory()->onboardedSeller(),
            'status' => SellerOrderStatus::PENDING,
            'subtotal_cents' => $subtotal,
            'shipping_cents' => $shipping,
            'platform_fee_cents' => $fee,
            'payout_cents' => $subtotal + $shipping - $fee,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => SellerOrderStatus::AWAITING_SHIPMENT]);
    }

    public function shipped(): static
    {
        return $this->state(fn () => [
            'status' => SellerOrderStatus::SHIPPED,
            'carrier' => 'UPS',
            'tracking_number' => '1Z'.Str::upper(Str::random(16)),
            'shipped_at' => now()->subDay(),
            'stripe_transfer_id' => 'tr_'.Str::random(20),
            'transferred_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->shipped()->state(fn () => [
            'status' => SellerOrderStatus::COMPLETED,
            'delivered_at' => now(),
        ]);
    }
}
