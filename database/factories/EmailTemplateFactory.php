<?php

namespace Database\Factories;

use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmailTemplate> */
class EmailTemplateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'test.'.fake()->unique()->word(),
            'name' => fake()->words(3, true),
            'subject' => 'Hello {{customer.name}}',
            'html_body' => '<p>Hello {{customer.name}}</p>',
            'text_body' => 'Hello {{customer.name}}',
            'enabled' => true,
            'version' => 1,
        ];
    }
}
