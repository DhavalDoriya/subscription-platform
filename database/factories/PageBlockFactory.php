<?php

namespace Database\Factories;

use App\Models\PageBlock;
use App\Models\Provider;
use App\Models\StorefrontPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PageBlock> */
class PageBlockFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'storefront_page_id' => StorefrontPage::factory(),
            'type' => 'text',
            'data' => ['body' => '<p>Hello</p>'],
            'position' => 0,
            'visible' => true,
        ];
    }
}
