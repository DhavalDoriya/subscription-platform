<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentAttempt> */
class PaymentAttemptFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'invoice_id' => fn (array $attributes) => Invoice::factory()->create(['provider_id' => $attributes['provider_id']])->id,
            'attempt_number' => 1,
            'kind' => 'initial',
            'status' => 'failed',
            'attempted_at' => now(),
        ];
    }
}
