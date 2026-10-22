<?php

namespace Database\Factories;

use App\Models\OrderIssue;
use App\Models\OrderIssueMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderIssueMessage> */
class OrderIssueMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_issue_id' => OrderIssue::factory(),
            'author_id' => User::factory(),
            'event' => null,
            'body' => $this->faker->sentence(12),
            'attachments' => null,
            'internal' => false,
        ];
    }

    /** A line written by the system (no author). */
    public function system(?string $event = null): static
    {
        return $this->state(fn () => ['author_id' => null, 'event' => $event]);
    }

    /** An admin-only note. */
    public function internal(): static
    {
        return $this->state(fn () => ['internal' => true]);
    }
}
