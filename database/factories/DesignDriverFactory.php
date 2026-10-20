<?php

namespace Database\Factories;

use App\Models\Design;
use App\Models\DesignDriver;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A driver used at a position of a design (a "component").
 *
 * @extends Factory<DesignDriver>
 */
class DesignDriverFactory extends Factory
{
    protected $model = DesignDriver::class;

    public function definition(): array
    {
        return [
            'design_id' => Design::factory(),
            'driver_id' => Driver::factory(),
            'position' => $this->faker->randomElement(['LF', 'LMF', 'MF', 'HMF', 'HF']),
            'quantity' => 1,
            'low_frequency' => 40,
            'high_frequency' => 2500,
            'air_volume' => $this->faker->randomFloat(1, 5, 60),
            'description' => '<p>'.$this->faker->sentence(12).'</p>',
            'specifications' => [],
            'frequency_files' => [],
            'impedance_files' => [],
            'other_files' => [],
        ];
    }
}
