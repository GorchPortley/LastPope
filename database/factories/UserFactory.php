<?php

namespace Database\Factories;

use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = User::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition(): array
    {
        static $password;

        return [
            'name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            'avatar' => 'demo/default.png',
            'verified' => 1,
            'password' => $password ?: $password = bcrypt('secret'),
            'remember_token' => Str::random(10),
        ];
    }

    /** An admin: sells as the platform (no Stripe Connect needed), may access everything. */
    public function admin(): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncRoles(['admin']));
    }

    /** Alias of admin(): admins are the platform sellers. */
    public function platformSeller(): static
    {
        return $this->admin();
    }

    /** A user with a seller profile whose Stripe onboarding is not finished (cannot be paid yet). */
    public function seller(array $profile = []): static
    {
        return $this->afterCreating(function (User $user) use ($profile) {
            SellerProfile::factory()->for($user)->create($profile);
        });
    }

    /** A seller whose Stripe account can take charges and receive payouts. */
    public function onboardedSeller(array $profile = []): static
    {
        return $this->afterCreating(function (User $user) use ($profile) {
            SellerProfile::factory()->onboarded()->for($user)->create($profile);
        });
    }
}
