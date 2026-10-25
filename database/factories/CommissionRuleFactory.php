<?php

namespace Database\Factories;

use App\Models\CommissionRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommissionRule> */
class CommissionRuleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['provider_id' => null, 'percent' => 10, 'effective_from' => now()->subDay()];
    }
}
