<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Price;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * Applies a normalised Stripe subscription (StripeSubscriptionState) to our mirror. Used by checkout, webhooks and the
 * nightly reconciliation, so all three agree. Stripe is the source of truth (FR-PM6); events older than the last one we
 * applied are ignored, which makes replays and out-of-order delivery harmless.
 */
class SubscriptionSync
{
    /**
     * @param  array<string, mixed>  $state
     * @param  int|null  $eventAt  Stripe event `created` (unix); null when the state was fetched directly and is current
     * @return bool whether the state was applied
     */
    public function apply(Subscription $subscription, array $state, ?int $eventAt = null): bool
    {
        if ($eventAt !== null && $subscription->stripe_event_at !== null && $eventAt < $subscription->stripe_event_at) {
            return false;
        }

        $status = SubscriptionStatus::fromStripe($state['status'], $subscription->status);

        if ($state['paused'] && $status->isLive()) {
            $status = SubscriptionStatus::Paused;
        }

        // An abandoned checkout we expired ourselves stays expired when Stripe later reports it cancelled.
        if ($subscription->status === SubscriptionStatus::Expired && $status === SubscriptionStatus::Canceled) {
            $status = SubscriptionStatus::Expired;
        }

        $before = [
            'status' => $subscription->status, 'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            'price_id' => $subscription->price_id, 'plan_name' => $subscription->plan?->name,
        ];

        $subscription->forceFill([
            'status' => $status,
            'stripe_subscription_id' => $state['id'] ?: $subscription->stripe_subscription_id,
            'cancel_at_period_end' => $state['cancel_at_period_end'],
            'current_period_start' => $this->date($state['current_period_start']),
            'current_period_end' => $this->date($state['current_period_end']),
            'trial_ends_at' => $this->date($state['trial_end']),
            'canceled_at' => $this->date($state['canceled_at']),
            'ended_at' => $this->date($state['ended_at']),
            'grace_ends_at' => $status === SubscriptionStatus::Grace ? $subscription->grace_ends_at : null,
            'paused_at' => $status === SubscriptionStatus::Paused ? ($subscription->paused_at ?? now()) : null,
            'pause_reason' => $status === SubscriptionStatus::Paused ? $subscription->pause_reason : null,
            'stripe_event_at' => $eventAt ?? $subscription->stripe_event_at,
        ]);

        $this->followPrice($subscription, $state['price_id']);
        $subscription->save();

        app(SubscriptionNotifier::class)->changed($subscription, $before);

        return true;
    }

    /** A plan change made in Stripe (or by us) moves the local price, plan and amount snapshot with it. */
    private function followPrice(Subscription $subscription, ?string $stripePriceId): void
    {
        if (! $stripePriceId || $subscription->price?->stripe_price_id === $stripePriceId) {
            return;
        }

        $price = Price::withoutGlobalScopes()->where('provider_id', $subscription->provider_id)->where('stripe_price_id', $stripePriceId)->first();

        if ($price) {
            $subscription->forceFill([
                'price_id' => $price->id, 'plan_id' => $price->plan_id, 'amount' => $price->amount,
                'currency' => $price->currency, 'interval' => $price->interval, 'interval_count' => $price->interval_count,
            ]);
            $subscription->unsetRelation('price');
        }
    }

    private function date(?int $timestamp): ?Carbon
    {
        return $timestamp ? Carbon::createFromTimestampUTC($timestamp) : null;
    }
}
