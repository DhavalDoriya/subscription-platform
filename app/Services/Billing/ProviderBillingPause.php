<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Provider;
use App\Models\Subscription;
use App\Services\Stripe\StripeGateway;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FR-P9: while a provider is inactive or suspended, collection on its subscriptions is paused on Stripe
 * (`pause_collection`), and resumed when it comes back. Only pauses made here are resumed, so a subscription paused by
 * the dunning engine stays paused. One subscription failing never blocks the others or the status change itself.
 */
class ProviderBillingPause
{
    public function __construct(private StripeGateway $stripe, private SubscriptionSync $sync) {}

    public function pause(Provider $provider): void
    {
        $this->each($provider, [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue], function (Subscription $subscription) use ($provider) {
            $state = $this->stripe->updateSubscription($provider->stripe_account_id, $subscription->stripe_subscription_id, ['pause_collection' => ['behavior' => 'void']], "provider-pause-{$subscription->id}-".now()->timestamp);

            $this->sync->apply($subscription, $state);
            $subscription->forceFill(['status' => SubscriptionStatus::Paused, 'pause_reason' => 'provider', 'paused_at' => $subscription->paused_at ?? now()])->save();
        });
    }

    public function resume(Provider $provider): void
    {
        $this->each($provider, [SubscriptionStatus::Paused], function (Subscription $subscription) use ($provider) {
            if ($subscription->pause_reason !== 'provider') {
                return;
            }

            $state = $this->stripe->updateSubscription($provider->stripe_account_id, $subscription->stripe_subscription_id, ['pause_collection' => ''], "provider-resume-{$subscription->id}-".now()->timestamp);

            $subscription->forceFill(['pause_reason' => null])->save();
            $this->sync->apply($subscription, $state);
        });
    }

    /** @param  array<int, SubscriptionStatus>  $statuses */
    private function each(Provider $provider, array $statuses, callable $action): void
    {
        if (! $provider->stripe_account_id) {
            return;
        }

        Subscription::withoutGlobalScopes()->where('provider_id', $provider->id)->whereIn('status', array_map(fn ($s) => $s->value, $statuses))
            ->whereNotNull('stripe_subscription_id')
            ->each(function (Subscription $subscription) use ($action, $provider) {
                try {
                    $action($subscription);
                } catch (Throwable $e) {
                    Log::error('Could not change collection on a subscription of a paused or resumed provider.', [
                        'provider' => $provider->id, 'subscription' => $subscription->id, 'error' => $e->getMessage(),
                    ]);
                }
            });
    }
}
