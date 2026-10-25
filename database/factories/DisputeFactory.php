<?php

namespace Database\Factories;

use App\Models\Dispute;
use App\Models\Payment;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Dispute> */
class DisputeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'payment_id' => fn (array $attributes) => Payment::factory()->create(['provider_id' => $attributes['provider_id']])->id,
            'stripe_dispute_id' => 'dp_'.fake()->unique()->bothify('????????????'),
            'amount' => 1900, 'currency' => 'usd', 'reason' => 'fraudulent', 'status' => 'needs_response',
        ];
    }
}
