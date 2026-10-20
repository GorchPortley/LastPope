<?php

namespace Database\Factories;

use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Listings are brand new, active and sold by an onboarded seller by default.
 * States: brandNew(), used(), openBox(), draft(), active(), paused(), soldOut(), pair(),
 * domesticOnly(), shipsInternationally(), forDriver($driver), forSeller($user).
 * (brandNew rather than new: Factory already has a static new().)
 *
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    public function definition(): array
    {
        $brand = $this->faker->randomElement(['Dayton Audio', 'SEAS', 'Peerless', 'Morel', 'Tang Band', 'Eminence', 'SB Acoustics']);
        $size = $this->faker->randomElement(['3', '4', '5.25', '6.5', '8', '10', '12']);
        $kind = $this->faker->randomElement(['woofer', 'midrange', 'tweeter', 'subwoofer']);

        return [
            'seller_id' => User::factory()->onboardedSeller(),
            'driver_id' => null,
            'title' => "{$brand} ".strtoupper($this->faker->bothify('??###')).' '.$size.'" '.$kind,
            'condition' => ListingCondition::NEW,
            'description' => $this->faker->paragraphs(2, true),
            'photos' => [],
            'price_cents' => $this->faker->numberBetween(1500, 40000),
            'quantity' => $this->faker->numberBetween(1, 6),
            'units_per_lot' => 1,
            'ships_from_country' => 'US',
            'shipping_domestic_cents' => 899,
            'shipping_international_cents' => 2499,
            'additional_item_shipping_cents' => 300,
            'handling_days' => 3,
            'returns_accepted' => true,
            'return_policy' => '30 days, buyer pays return shipping.',
            'status' => ListingStatus::ACTIVE,
        ];
    }

    public function forSeller(User $seller): static
    {
        return $this->state(fn () => ['seller_id' => $seller->getKey()]);
    }

    public function forDriver(Driver $driver): static
    {
        return $this->state(fn () => ['driver_id' => $driver->getKey()]);
    }

    public function brandNew(): static
    {
        return $this->state(fn () => ['condition' => ListingCondition::NEW]);
    }

    public function openBox(): static
    {
        return $this->state(fn () => ['condition' => ListingCondition::OPEN_BOX]);
    }

    /** A used listing (random used condition unless one is given). */
    public function used(?ListingCondition $condition = null): static
    {
        return $this->state(fn () => [
            'condition' => $condition ?? $this->faker->randomElement(ListingCondition::usedCases()),
            'quantity' => 1,
            'returns_accepted' => false,
            'return_policy' => null,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => ListingStatus::ACTIVE]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => ListingStatus::DRAFT]);
    }

    public function paused(): static
    {
        return $this->state(fn () => ['status' => ListingStatus::PAUSED]);
    }

    public function soldOut(): static
    {
        return $this->state(fn () => ['status' => ListingStatus::SOLD_OUT, 'quantity' => 0]);
    }

    /** Sold as a pair: one unit of the listing is two drivers. */
    public function pair(): static
    {
        return $this->state(fn () => ['units_per_lot' => 2, 'quantity' => 1]);
    }

    public function domesticOnly(): static
    {
        return $this->state(fn () => ['shipping_international_cents' => null]);
    }

    public function shipsInternationally(?int $cents = null): static
    {
        return $this->state(fn () => ['shipping_international_cents' => $cents ?? 2499]);
    }
}
