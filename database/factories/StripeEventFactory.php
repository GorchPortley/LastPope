<?php

namespace Database\Factories;

use App\Models\StripeEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StripeEvent>
 */
class StripeEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => 'evt_'.Str::random(24),
            'type' => 'checkout.session.completed',
            'processed_at' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn () => ['processed_at' => now()]);
    }
}
