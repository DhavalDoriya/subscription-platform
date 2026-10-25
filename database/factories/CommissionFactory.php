<?php

namespace Database\Factories;

use App\Models\Commission;
use App\Models\Payment;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Commission> */
class CommissionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'payment_id' => fn (array $attributes) => Payment::factory()->create(['provider_id' => $attributes['provider_id']])->id,
            'currency' => 'usd', 'gross_amount' => 2000, 'rate_percent' => 10, 'commission_amount' => 200,
        ];
    }
}
