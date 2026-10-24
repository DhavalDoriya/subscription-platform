<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Media> */
class MediaFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $provider = Provider::factory();

        return [
            'provider_id' => $provider,
            'disk' => 'public',
            'path' => 'providers/'.fake()->uuid().'/'.Str::uuid().'.png',
            'original_name' => fake()->word().'.png',
            'mime_type' => 'image/png',
            'size' => 2048,
            'width' => 800,
            'height' => 600,
            'alt' => null,
        ];
    }
}
