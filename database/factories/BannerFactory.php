<?php

namespace Database\Factories;

use App\Models\Banner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Banner>
 */
class BannerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'image_path' => 'demo/Design_Placeholder.jpg',
            'location' => 'design_page',
            'start_date' => null,
            'end_date' => null,
            'is_active' => true,
            'priority' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function at(string $location): static
    {
        return $this->state(fn () => ['location' => $location]);
    }
}
