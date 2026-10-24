<?php

namespace App\Services\Stripe\Webhooks;

use App\Models\Provider;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionSync;
use App\Services\Stripe\StripeSubscriptionState;
use App\Support\Tenancy\TenantContext;

/** customer.subscription.created/updated/deleted: keep our subscription in step with Stripe (FR-C2, FR-PM6). */
class SubscriptionEventHandler extends ConnectEventHandler
{
    public function __construct(TenantContext $tenancy, private SubscriptionSync $sync)
    {
        parent::__construct($tenancy);
    }

    protected function process(Provider $provider, array $object, int $at, string $type): void
    {
        $subscription = Subscription::where('stripe_subscription_id', $object['id'] ?? '')->first()
            ?? Subscription::find($object['metadata']['subscription_id'] ?? null);

        if ($subscription) {
            $this->sync->apply($subscription, StripeSubscriptionState::normalize($object), $at);
        }
    }
}
