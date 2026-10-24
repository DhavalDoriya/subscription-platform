<?php

namespace App\Services\Stripe\Webhooks;

use App\Models\Provider;
use App\Services\Stripe\ProviderStripeSync;
use App\Services\Stripe\StripeAccountState;

/** account.updated: keep a provider's onboarding status in step with their connected account (FR-P4). */
class AccountUpdatedHandler implements StripeEventHandler
{
    public function __construct(private ProviderStripeSync $sync) {}

    public function handle(array $event): void
    {
        $state = StripeAccountState::normalize($event['data']['object'] ?? []);
        $provider = $state['id'] ? Provider::where('stripe_account_id', $state['id'])->first() : null;

        if ($provider) {
            $this->sync->apply($provider, $state);
        }
    }
}
