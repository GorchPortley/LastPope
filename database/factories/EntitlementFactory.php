<?php

namespace Database\Factories;

use App\Contracts\Purchasable;
use App\Enums\EntitlementSource;
use App\Models\Design;
use App\Models\Entitlement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * An active purchase entitlement for a design by default. States: free(), owner(), adminGrant(),
 * revoked(), forProduct($design), forUser($user).
 *
 * @extends Factory<Entitlement>
 */
class EntitlementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'entitleable_type' => (new Design)->getMorphClass(),
            'entitleable_id' => Design::factory(),
            'order_item_id' => null,
            'source' => EntitlementSource::PURCHASE,
            'granted_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->getKey()]);
    }

    public function forProduct(Purchasable $product): static
    {
        /** @var Model&Purchasable $product */
        return $this->state(fn () => [
            'entitleable_type' => $product->getMorphClass(),
            'entitleable_id' => $product->getKey(),
        ]);
    }

    public function free(): static
    {
        return $this->state(fn () => ['source' => EntitlementSource::FREE]);
    }

    public function owner(): static
    {
        return $this->state(fn () => ['source' => EntitlementSource::OWNER]);
    }

    public function adminGrant(): static
    {
        return $this->state(fn () => ['source' => EntitlementSource::ADMIN_GRANT]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
