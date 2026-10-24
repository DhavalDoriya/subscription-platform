<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Provider;
use App\Models\ProviderCustomer;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'provider_customer_id' => ProviderCustomer::factory(),
            'plan_id' => Plan::factory(),
            'price_id' => Price::factory(),
            'status' => SubscriptionStatus::Active,
            'amount' => 1900,
            'currency' => 'usd',
            'interval' => 'month',
            'interval_count' => 1,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
            'stripe_subscription_id' => 'sub_'.fake()->unique()->bothify('????????????'),
        ];
    }
}
