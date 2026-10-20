<?php

namespace Database\Factories;

use App\Contracts\Purchasable;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'purchasable_type' => (new Listing)->getMorphClass(),
            'purchasable_id' => Listing::factory(),
            'quantity' => 1,
            'unit_price_cents' => fn (array $attributes) => Listing::find($attributes['purchasable_id'])->price_cents,
        ];
    }

    /** Make this line an existing Design, Driver or Listing, priced as it is now. */
    public function forPurchasable(Purchasable $purchasable, int $quantity = 1): static
    {
        /** @var Model&Purchasable $purchasable */
        return $this->state(fn () => [
            'purchasable_type' => $purchasable->getMorphClass(),
            'purchasable_id' => $purchasable->getKey(),
            'quantity' => $purchasable->isDigital() ? 1 : $quantity,
            'unit_price_cents' => $purchasable->purchasablePriceCents(),
        ]);
    }
}
