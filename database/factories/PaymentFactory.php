<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'status' => 'succeeded',
            'amount' => 1900,
            'amount_refunded' => 0,
            'currency' => 'usd',
            'paid_at' => now(),
            'stripe_payment_intent_id' => 'pi_'.fake()->unique()->bothify('????????????'),
            'stripe_charge_id' => 'ch_'.fake()->unique()->bothify('????????????'),
        ];
    }
}
