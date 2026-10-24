<?php

namespace Database\Factories;

use App\Models\Provider;
use App\Models\ProviderCustomer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProviderCustomer> */
class ProviderCustomerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'user_id' => User::factory(),
        ];
    }
}
