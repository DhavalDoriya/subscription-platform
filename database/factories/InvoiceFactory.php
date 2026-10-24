<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invoice> */
class InvoiceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'status' => 'paid',
            'currency' => 'usd',
            'amount_due' => 1900,
            'amount_paid' => 1900,
            'paid_at' => now(),
            'stripe_invoice_id' => 'in_'.fake()->unique()->bothify('????????????'),
        ];
    }
}
