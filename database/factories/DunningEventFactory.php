<?php

namespace Database\Factories;

use App\Models\DunningEvent;
use App\Models\Invoice;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DunningEvent> */
class DunningEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'invoice_id' => fn (array $attributes) => Invoice::factory()->create(['provider_id' => $attributes['provider_id']])->id,
            'type' => 'failure_notice',
            'occurred_at' => now(),
        ];
    }
}
