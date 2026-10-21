<?php

namespace Database\Factories;

use App\Enums\DriverCategoryEnum;
use App\Models\Driver;
use App\Models\DriverSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Drivers are active by default. Useful states: active(), draft(), withSnapshot(), official();
 * pass ->for($user, 'creator') to choose the owner. (New units are sold through a store Listing:
 * Listing::factory()->inStore($driver).)
 *
 * @extends Factory<Driver>
 */
class DriverFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'card_image' => ['demo/Design_Placeholder.jpg'],
            'brand' => $this->faker->randomElement([
                'ScanSpeak', 'SEAS', 'Peerless', 'Vifa', 'Dayton Audio', 'Fountek', 'Tang Band', 'Morel', 'Fostex',
                'Eminence', 'Audax', 'AuraSound', 'SB Acoustics', 'Wavecor', 'Tymphany', 'Davis Acoustics',
                'Audio Technology', 'HiVi', 'Accuton', 'CSS Audio',
            ]),
            'model' => strtoupper($this->faker->bothify('??###-#')),
            'tag' => $this->faker->sentence(4),
            'active' => true,
            'category' => $this->faker->randomElement(array_map(fn (DriverCategoryEnum $c) => $c->value, DriverCategoryEnum::cases())),
            'size' => $this->faker->randomElement([1, 2, 3, 4, 5, 5.25, 5.5, 6, 6.5, 7, 8, 10, 12, 15, 18, 21]),
            'impedance' => $this->faker->randomElement([1, 2, 4, 6, 8]),
            'power' => $this->faker->numberBetween(20, 500),
            'msrp_cents' => $this->faker->numberBetween(1500, 90000),
            'link' => $this->faker->url(),
            'summary' => $this->faker->paragraph(),
            'description' => '<p>'.$this->faker->paragraph().'</p>',
            'factory_specs' => [],
            'frequency_files' => [],
            'impedance_files' => [],
            'other_files' => [],
            'official' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['active' => true]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    public function official(): static
    {
        return $this->state(fn () => ['official' => true]);
    }

    /** Attach a snapshot row; with $withFile the ZIP is also written to the private disk. */
    public function withSnapshot(string $version = 'v1.0', bool $withFile = false): static
    {
        return $this->afterCreating(function (Driver $driver) use ($version, $withFile) {
            $factory = DriverSnapshot::factory()->for($driver)->state(['version' => $version]);

            if ($withFile) {
                $factory = $factory->withFile();
            }

            $factory->create();
        });
    }
}
