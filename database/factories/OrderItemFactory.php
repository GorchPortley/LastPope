<?php

namespace Database\Factories;

use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare order item. For consistent orders with items use Order::factory()->paid()...
 *
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'seller_order_id' => SellerOrder::factory(),
            'order_id' => fn (array $attributes) => SellerOrder::find($attributes['seller_order_id'])->order_id,
            'purchasable_type' => (new Listing)->getMorphClass(),
            'purchasable_id' => Listing::factory(),
            'title' => fn (array $attributes) => Listing::find($attributes['purchasable_id'])->title,
            'is_digital' => false,
            'quantity' => 1,
            'unit_price_cents' => fn (array $attributes) => Listing::find($attributes['purchasable_id'])->price_cents,
            'shipping_cents' => 0,
            'line_total_cents' => fn (array $attributes) => $attributes['unit_price_cents'] * $attributes['quantity'],
        ];
    }
}
