<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Provider;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Refund> */
class RefundFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'payment_id' => Payment::factory(),
            'amount' => 500,
            'currency' => 'usd',
            'status' => 'succeeded',
            'stripe_refund_id' => 're_'.fake()->unique()->bothify('????????????'),
        ];
    }
}
