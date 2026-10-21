<?php

namespace Database\Factories;

use App\Enums\ListingCategory;
use App\Enums\ListingChannel;
use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Listings are brand new, active marketplace listings (category kit) sold by an onboarded seller by default.
 * States: inStore($driver?) (SDLabs' own new driver, sold by an admin), kit(), completedSpeaker(), usedGear(),
 * brandNew(), used(), openBox(), draft(), active(), paused(), soldOut(), pair(), domesticOnly(),
 * shipsInternationally(), forDriver($driver), forDesign($design), forSeller($user).
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
            'channel' => ListingChannel::MARKETPLACE,
            'category' => ListingCategory::KIT,
            'driver_id' => null,
            'design_id' => null,
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

    /** A kit or completed speaker built from a site design. */
    public function forDesign(Design $design): static
    {
        return $this->state(fn () => ['design_id' => $design->getKey()]);
    }

    /**
     * SDLabs' own new driver: store channel, sold by an admin, condition new, category driver, stock 5-40.
     * Pass the catalogue driver (one is created otherwise) and the admin who sells it (a new one otherwise).
     */
    public function inStore(?Driver $driver = null, ?User $admin = null): static
    {
        return $this->state(fn () => [
            'channel' => ListingChannel::STORE,
            'category' => ListingCategory::DRIVER,
            'condition' => ListingCondition::NEW,
            'seller_id' => $admin?->getKey() ?? User::factory()->admin(),
            'driver_id' => $driver?->getKey() ?? Driver::factory(),
            'quantity' => $this->faker->numberBetween(5, 40),
            'units_per_lot' => 1,
        ]);
    }

    public function kit(): static
    {
        return $this->state(fn () => ['channel' => ListingChannel::MARKETPLACE, 'category' => ListingCategory::KIT]);
    }

    public function completedSpeaker(): static
    {
        return $this->state(fn () => ['channel' => ListingChannel::MARKETPLACE, 'category' => ListingCategory::COMPLETED_SPEAKER]);
    }

    /** Used gear (a used condition, so pass a condition to ->used($condition) if it matters). */
    public function usedGear(?ListingCondition $condition = null): static
    {
        return $this->used($condition);
    }

    public function brandNew(): static
    {
        return $this->state(fn () => ['condition' => ListingCondition::NEW]);
    }

    public function openBox(): static
    {
        return $this->state(fn () => ['condition' => ListingCondition::OPEN_BOX]);
    }

    /** A used listing (random used condition unless one is given): used gear. */
    public function used(?ListingCondition $condition = null): static
    {
        return $this->state(fn () => [
            'channel' => ListingChannel::MARKETPLACE,
            'category' => ListingCategory::USED_GEAR,
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
