<?php

namespace Database\Factories;

use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SellerProfile>
 */
class SellerProfileFactory extends Factory
{
    public function definition(): array
    {
        $shop = $this->faker->unique()->company().' Audio';

        return [
            'user_id' => User::factory(),
            'shop_name' => $shop,
            'slug' => Str::slug($shop).'-'.Str::lower(Str::random(4)),
            'bio' => $this->faker->paragraph(),
            'country' => 'US',
            'stripe_account_id' => 'acct_'.Str::random(16),
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => false,
            'commission_bps' => null,
        ];
    }

    /** Stripe onboarding finished: the seller can be paid. */
    public function onboarded(): static
    {
        return $this->state(fn () => [
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
        ]);
    }

    /** Onboarding started (details submitted) but Stripe has not enabled payouts yet. */
    public function pending(): static
    {
        return $this->state(fn () => [
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => true,
        ]);
    }

    /** Has not started Stripe onboarding at all. */
    public function withoutStripe(): static
    {
        return $this->state(fn () => [
            'stripe_account_id' => null,
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => false,
        ]);
    }

    /** A seller-specific commission (basis points) instead of the marketplace default. */
    public function commission(int $bps): static
    {
        return $this->state(fn () => ['commission_bps' => $bps]);
    }
}
