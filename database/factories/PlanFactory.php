<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'features' => [],
            'status' => 'draft',
            'visibility' => 'public',
            'trial_days' => 0,
            'sort_order' => 0,
        ];
    }
}
