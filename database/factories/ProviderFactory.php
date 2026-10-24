<?php

namespace Database\Factories;

use App\Enums\ProviderStatus;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Provider> */
class ProviderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => 'p'.Str::lower(Str::random(10)),
            'status' => ProviderStatus::Active,
            'business_category' => 'other',
            'country' => 'US',
            'support_email' => fake()->safeEmail(),
            'currency' => 'usd',
            'timezone' => 'UTC',
            'approved_at' => now(),
        ];
    }
}
