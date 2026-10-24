<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use App\Models\Provider;
use App\Models\ProviderCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentMethod> */
class PaymentMethodFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'provider_customer_id' => ProviderCustomer::factory(),
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => (int) now()->addYears(2)->format('Y'),
            'is_default' => false,
            'stripe_payment_method_id' => 'pm_'.fake()->unique()->bothify('????????????'),
        ];
    }
}
