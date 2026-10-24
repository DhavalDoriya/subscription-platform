<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Exceptions\ApiException;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Stripe\StripeGateway;

/** Cancel, resume and change plan (FR-C3, FR-C4). Stripe is told first; our mirror follows from its answer. */
class SubscriptionService
{
    public const IMMEDIATE = 'immediate';

    public const NEXT_CYCLE = 'next_cycle';

    public function __construct(private StripeGateway $stripe, private SubscriptionSync $sync) {}

    public function cancel(Subscription $subscription, bool $atPeriodEnd, ?User $actor = null): Subscription
    {
        if (! $subscription->status->isLive() || ($atPeriodEnd && $subscription->cancel_at_period_end)) {
            throw new ApiException(__('api.billing.not_cancelable'), 409, 'not_cancelable');
        }

        $account = $this->account($subscription);

        $state = $atPeriodEnd
            ? $this->stripe->updateSubscription($account, $subscription->stripe_subscription_id, ['cancel_at_period_end' => true], "subscription-{$subscription->id}-cancel-at-period-end")
            : $this->stripe->cancelSubscription($account, $subscription->stripe_subscription_id, "subscription-{$subscription->id}-cancel-now");

        $this->sync->apply($subscription, $state);
        $this->log($subscription, $atPeriodEnd ? 'subscription.cancel_scheduled' : 'subscription.canceled', $actor);

        return $subscription;
    }

    public function resume(Subscription $subscription, ?User $actor = null): Subscription
    {
        if (! $subscription->status->isLive() || ! $subscription->cancel_at_period_end) {
            throw new ApiException(__('api.billing.not_resumable'), 409, 'not_resumable');
        }

        $state = $this->stripe->updateSubscription($this->account($subscription), $subscription->stripe_subscription_id, ['cancel_at_period_end' => false], "subscription-{$subscription->id}-resume-".$subscription->updated_at->timestamp);

        $this->sync->apply($subscription, $state);
        $this->log($subscription, 'subscription.resumed', $actor);

        return $subscription;
    }

    /** @return array{timing: string, credit: int, charge: int, net: int, currency: string, effective_at: ?string} */
    public function previewChange(Subscription $subscription, Price $price, string $timing): array
    {
        $this->guardChange($subscription, $price);

        $periodEnd = $subscription->current_period_end;

        if ($timing === self::NEXT_CYCLE || ! $periodEnd || ! $subscription->current_period_start) {
            return ['timing' => $timing, 'credit' => 0, 'charge' => 0, 'net' => 0, 'currency' => $subscription->currency, 'effective_at' => $periodEnd?->toIso8601String()];
        }

        $numbers = ProrationCalculator::preview($subscription->amount, $price->amount, $subscription->current_period_start->timestamp, $periodEnd->timestamp, now()->timestamp);

        return ['timing' => $timing, 'credit' => $numbers['credit'], 'charge' => $numbers['charge'], 'net' => $numbers['net'], 'currency' => $subscription->currency, 'effective_at' => now()->toIso8601String()];
    }

    public function changePlan(Subscription $subscription, Price $price, string $timing, ?User $actor = null): Subscription
    {
        $this->guardChange($subscription, $price);

        $account = $this->account($subscription);
        $current = $this->stripe->retrieveSubscription($account, $subscription->stripe_subscription_id);

        // "Next cycle" switches the price without charging or crediting the difference now; the new price applies from the next invoice.
        $state = $this->stripe->updateSubscription($account, $subscription->stripe_subscription_id, [
            'items' => [['id' => $current['item_id'], 'price' => $price->stripe_price_id]],
            'proration_behavior' => $timing === self::IMMEDIATE ? 'create_prorations' : 'none',
        ], "subscription-{$subscription->id}-change-{$price->id}-{$subscription->updated_at->timestamp}");

        $this->sync->apply($subscription, $state);
        $this->log($subscription, 'subscription.plan_changed', $actor, ['price_id' => $price->id, 'timing' => $timing]);

        return $subscription;
    }

    private function guardChange(Subscription $subscription, Price $price): void
    {
        if (! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)) {
            throw new ApiException(__('api.billing.not_changeable'), 409, 'not_changeable');
        }

        if ($price->id === $subscription->price_id) {
            throw new ApiException(__('api.billing.same_price'), 409, 'same_price');
        }

        if ($price->currency !== $subscription->currency) {
            throw new ApiException(__('api.billing.currency_mismatch'), 409, 'currency_mismatch');
        }
    }

    private function account(Subscription $subscription): string
    {
        return $subscription->provider->stripe_account_id;
    }

    /** @param  array<string, mixed>  $properties */
    private function log(Subscription $subscription, string $event, ?User $actor, array $properties = []): void
    {
        $log = activity()->performedOn($subscription)->withProperties($properties);

        if ($actor) {
            $log->causedBy($actor);
        }

        $log->log($event);
    }
}
