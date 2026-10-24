<?php

namespace Database\Factories;

use App\Models\Provider;
use App\Models\StorefrontPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StorefrontPage> */
class StorefrontPageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['provider_id' => Provider::factory(), 'seo' => []];
    }
}
