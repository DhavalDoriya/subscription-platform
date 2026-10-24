<?php

namespace App\Services\Billing;

use App\Models\Provider;
use App\Models\Subscription;
use App\Services\Stripe\StripeGateway;
use Throwable;

/**
 * Nightly check that our mirror still matches Stripe (FR-PM6). Webhooks can be missed; this finds subscriptions that
 * drifted, repairs them from Stripe (the source of truth) and leaves an audit entry for each repair.
 */
class Reconciler
{
    public function __construct(private StripeGateway $stripe, private SubscriptionSync $sync) {}

    /** @return array{providers: int, checked: int, drifted: int, errors: int} */
    public function run(): array
    {
        $report = ['providers' => 0, 'checked' => 0, 'drifted' => 0, 'errors' => 0];

        foreach (Provider::where('stripe_status', 'enabled')->whereNotNull('stripe_account_id')->cursor() as $provider) {
            $report['providers']++;

            try {
                $this->reconcile($provider, $report);
            } catch (Throwable $e) {
                $report['errors']++;
                report($e); // one broken account must not stop the rest
            }
        }

        return $report;
    }

    /** @param  array<string, int>  $report */
    private function reconcile(Provider $provider, array &$report): void
    {
        $remote = collect($this->stripe->listSubscriptions($provider->stripe_account_id))->keyBy('id');

        Subscription::withoutGlobalScopes()->where('provider_id', $provider->id)->whereNotNull('stripe_subscription_id')
            ->each(function (Subscription $subscription) use ($remote, &$report) {
                $state = $remote->get($subscription->stripe_subscription_id);

                if (! $state) {
                    return;
                }

                $report['checked']++;
                $before = $this->snapshot($subscription);
                $this->sync->apply($subscription, $state);

                if ($before !== $this->snapshot($subscription)) {
                    $report['drifted']++;
                    activity()->performedOn($subscription)->withProperties(['before' => $before, 'after' => $this->snapshot($subscription)])->log('billing.drift_repaired');
                }
            });
    }

    /** @return array<string, mixed> */
    private function snapshot(Subscription $subscription): array
    {
        return [
            'status' => $subscription->status->value,
            'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            'current_period_end' => $subscription->current_period_end?->timestamp,
            'price_id' => $subscription->price_id,
        ];
    }
}
