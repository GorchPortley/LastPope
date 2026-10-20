<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Contracts\Purchasable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
        ];
    }

    /**
     * Put the given purchasables in the cart (each [Purchasable, quantity] or just a Purchasable),
     * priced as they are right now.
     *
     * @param  array<int, Purchasable|array{0: Purchasable, 1: int}>  $items
     */
    public function withItems(array $items): static
    {
        return $this->afterCreating(function (Cart $cart) use ($items) {
            foreach ($items as $entry) {
                [$purchasable, $quantity] = is_array($entry) ? [$entry[0], $entry[1] ?? 1] : [$entry, 1];

                CartItem::factory()->forPurchasable($purchasable, $quantity)->create(['cart_id' => $cart->getKey()]);
            }
        });
    }
}
