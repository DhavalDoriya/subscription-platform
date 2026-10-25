<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Exceptions\ApiException;
use App\Models\Price;
use App\Models\Provider;
use App\Models\ProviderCustomer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Commission\CommissionResolver;
use App\Services\Settings\SettingsService;
use App\Services\Stripe\StripeGateway;
use Illuminate\Support\Facades\DB;

/**
 * Joining a provider (PRD 3.5, FR-C1). The customer is linked to the provider only here, when they actually subscribe or
 * start a trial; browsing never links. The subscription is created on Stripe as "incomplete" and becomes live when the
 * customer confirms payment in the browser and Stripe's webhooks say so.
 */
class CheckoutService
{
    public function __construct(private StripeGateway $stripe, private SettingsService $settings, private SubscriptionSync $sync, private CommissionResolver $commission) {}

    /** @return array{subscription: Subscription, client_secret: ?string, client_secret_type: ?string} */
    public function start(User $user, Provider $provider, Price $price): array
    {
        if (! $provider->status->isOperational() || $provider->stripe_status !== 'enabled' || ! $provider->stripe_account_id) {
            throw new ApiException(__('api.billing.checkout_unavailable'), 409, 'checkout_unavailable');
        }

        return DB::transaction(function () use ($user, $provider, $price) {
            $link = ProviderCustomer::firstOrCreate(['provider_id' => $provider->id, 'user_id' => $user->id]);
            $this->guardNotAlreadySubscribed($link, $price);
            $this->ensureStripeCustomer($provider, $link, $user);
            $this->abandonUnfinished($provider, $link, $price);

            $subscription = Subscription::create([
                'provider_id' => $provider->id,
                'provider_customer_id' => $link->id,
                'plan_id' => $price->plan_id,
                'price_id' => $price->id,
                'status' => SubscriptionStatus::Incomplete,
                'amount' => $price->amount,
                'currency' => $price->currency,
                'interval' => $price->interval,
                'interval_count' => $price->interval_count,
                'application_fee_percent' => $this->commission->rateFor($provider), // FR-CM3: fixed for this subscription from today's rate
            ]);

            $state = $this->stripe->createSubscription($provider->stripe_account_id, $this->subscriptionParams($provider, $link, $subscription, $price), "subscription-{$subscription->id}-create");
            $this->sync->apply($subscription, $state);

            return ['subscription' => $subscription, 'client_secret' => $state['client_secret'], 'client_secret_type' => $state['client_secret_type']];
        });
    }

    private function guardNotAlreadySubscribed(ProviderCustomer $link, Price $price): void
    {
        $live = Subscription::where('provider_customer_id', $link->id)->where('plan_id', $price->plan_id)
            ->whereIn('status', collect(SubscriptionStatus::cases())->filter->isLive()->map->value->all())->exists();

        if ($live) {
            throw new ApiException(__('api.billing.already_subscribed'), 409, 'already_subscribed');
        }
    }

    /** The key is derived from the pair, so even a retried request after a rollback gets the same Stripe customer back. */
    private function ensureStripeCustomer(Provider $provider, ProviderCustomer $link, User $user): void
    {
        if ($link->stripe_customer_id) {
            return;
        }

        $link->stripe_customer_id = $this->stripe->createCustomer($provider->stripe_account_id, [
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['user_id' => $user->id, 'provider_id' => $provider->id],
        ], "provider-{$provider->id}-user-{$user->id}-customer");
        $link->save();
    }

    /** An unfinished earlier attempt at this plan is cancelled so the customer never ends up with two pending charges. */
    private function abandonUnfinished(Provider $provider, ProviderCustomer $link, Price $price): void
    {
        $unfinished = Subscription::where('provider_customer_id', $link->id)->where('plan_id', $price->plan_id)
            ->where('status', SubscriptionStatus::Incomplete)->get();

        foreach ($unfinished as $subscription) {
            if ($subscription->stripe_subscription_id) {
                $this->stripe->cancelSubscription($provider->stripe_account_id, $subscription->stripe_subscription_id, "subscription-{$subscription->id}-abandon");
            }

            $subscription->update(['status' => SubscriptionStatus::Expired, 'ended_at' => now()]);
        }
    }

    /** @return array<string, mixed> */
    private function subscriptionParams(Provider $provider, ProviderCustomer $link, Subscription $subscription, Price $price): array
    {
        $params = [
            'customer' => $link->stripe_customer_id,
            'items' => [['price' => $price->stripe_price_id]],
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
            'metadata' => ['subscription_id' => $subscription->id, 'provider_id' => $provider->id],
        ];

        if ($price->plan->trial_days > 0) {
            $params['trial_period_days'] = $price->plan->trial_days;
        }

        if ((float) $subscription->application_fee_percent > 0) {
            $params['application_fee_percent'] = (float) $subscription->application_fee_percent;
        }

        return $params;
    }
}
