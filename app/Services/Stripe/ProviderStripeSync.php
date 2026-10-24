<?php

namespace App\Services\Stripe;

use App\Enums\ProviderStatus;
use App\Models\Provider;
use App\Models\User;
use App\Services\Providers\ProviderLifecycle;

/** Applies a Stripe account's state to a provider: records the onboarding status and goes live once Stripe says enabled. */
class ProviderStripeSync
{
    public function __construct(private ProviderLifecycle $lifecycle) {}

    /** @param  array{id: string, details_submitted: bool, charges_enabled: bool, payouts_enabled: bool}  $state */
    public function apply(Provider $provider, array $state, ?User $actor = null): Provider
    {
        $new = StripeAccountState::status($state);
        $old = $provider->stripe_status;

        if ($new !== $old) {
            $provider->forceFill(['stripe_status' => $new])->save();

            $log = activity()->performedOn($provider)->withProperties(['from' => $old, 'to' => $new]);
            if ($actor) {
                $log->causedBy($actor);
            }
            $log->log('provider.stripe_status_changed');
        }

        // Approved + payments enabled = open for business. A paused (inactive/suspended) provider is never moved by Stripe.
        if ($new === 'enabled' && $provider->status === ProviderStatus::Approved) {
            $this->lifecycle->transition($provider, ProviderStatus::Active, $actor);
        }

        return $provider;
    }
}
