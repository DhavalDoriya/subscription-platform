<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Price;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Price> */
class PriceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'plan_id' => Plan::factory(),
            'amount' => 1900,
            'currency' => 'usd',
            'interval' => 'month',
            'interval_count' => 1,
            'status' => 'active',
        ];
    }
}
