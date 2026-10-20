<?php

namespace Database\Factories;

use App\Enums\DesignCategoryEnum;
use App\Models\Design;
use App\Models\DesignSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Designs are free and active by default. Useful states: free(), paid(), active(), draft(),
 * withSnapshot(), official(); pass ->for($user, 'designer') to choose the owner.
 *
 * @extends Factory<Design>
 */
class DesignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->unique()->words(2, true).' '.$this->faker->randomElement(['Monitor', 'Tower', 'Bookshelf', 'Sub', 'Studio']),
            'tag' => $this->faker->sentence(3),
            'active' => true,
            'card_image' => ['demo/Design_Placeholder.jpg'],
            'category' => $this->faker->randomElement(array_map(fn (DesignCategoryEnum $c) => $c->value, DesignCategoryEnum::cases())),
            'price_cents' => 0,
            'build_cost_cents' => $this->faker->numberBetween(15000, 150000),
            'impedance' => $this->faker->randomElement([4, 6, 8]),
            'power' => $this->faker->randomElement([50, 100, 150, 200, 300]),
            'summary' => '<p>'.$this->faker->paragraph(3).'</p>',
            'description' => '<p>'.$this->faker->paragraph(6).'</p>',
            'bill_of_materials' => [],
            'frd_files' => [],
            'enclosure_files' => [],
            'electronic_files' => [],
            'design_other_files' => [],
            'official' => false,
        ];
    }

    public function free(): static
    {
        return $this->state(fn () => ['price_cents' => 0]);
    }

    public function paid(?int $cents = null): static
    {
        return $this->state(fn () => ['price_cents' => $cents ?? $this->faker->numberBetween(5, 60) * 100 - 1]);
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

    /**
     * Attach a snapshot row. With $withFile the ZIP is also written to the private disk (use
     * Storage::fake('private') in tests).
     */
    public function withSnapshot(string $version = 'v1.0', bool $withFile = false): static
    {
        return $this->afterCreating(function (Design $design) use ($version, $withFile) {
            $factory = DesignSnapshot::factory()->for($design)->state(['version' => $version]);

            if ($withFile) {
                $factory = $factory->withFile();
            }

            $factory->create();
        });
    }
}
