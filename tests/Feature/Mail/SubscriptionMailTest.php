<?php

use App\Models\Price;
use App\Models\User;
use App\Services\Billing\SubscriptionSync;
use App\Services\Mail\TemplateMail;

/** Applies a Stripe-side change to a subscription of a fresh customer and returns the templates mailed. */
function mailedAfter(string $status, array $stripe): array
{
    [$provider, $plan, $price] = liveStorefront();
    $owner = User::factory()->create();
    $provider->members()->create(['user_id' => $owner->id, 'role' => 'owner']);
    $customer = customerSession();
    $subscription = subscribe($customer, $provider, $plan, $price, ['status' => $status]);
    $state = array_merge(fakeStripe()->subscriptions[$subscription->stripe_subscription_id], $stripe);

    app(SubscriptionSync::class)->apply($subscription, $state);

    return collect(app(TemplateMail::class)->sent)->map(fn ($m) => $m['template'].' > '.$m['to'])->sort()->values()->all();
}

it('FR-E1 mails the customer and the provider owner when a subscription becomes active', function () {
    $sent = mailedAfter('incomplete', ['status' => 'active']);

    expect($sent)->toHaveCount(2)
        ->and(collect($sent)->map(fn ($s) => explode(' > ', $s)[0])->all())->toBe(['customer.subscription_started', 'provider.new_subscriber']);
});

it('FR-E1 does not mail again for a renewal of an active subscription', function () {
    expect(mailedAfter('active', ['status' => 'active']))->toBe([]);
});

it('FR-E1 mails both sides when a subscription is canceled, and the customer when it is resumed', function () {
    expect(collect(mailedAfter('active', ['cancel_at_period_end' => true]))->map(fn ($s) => explode(' > ', $s)[0])->all())
        ->toBe(['customer.subscription_canceled', 'provider.plan_canceled']);

});

it('FR-E1 mails both sides when a subscription is canceled immediately', function () {
    expect(collect(mailedAfter('active', ['status' => 'canceled', 'ended_at' => now()->timestamp, 'canceled_at' => now()->timestamp]))->map(fn ($s) => explode(' > ', $s)[0])->all())
        ->toBe(['customer.subscription_canceled', 'provider.plan_canceled']);
});

it('FR-E1 mails the customer when a scheduled cancellation is withdrawn', function () {
    [$provider, $plan, $price] = liveStorefront();
    $subscription = subscribe(customerSession(), $provider, $plan, $price, ['cancel_at_period_end' => true]);
    $state = array_merge(fakeStripe()->subscriptions[$subscription->stripe_subscription_id], ['cancel_at_period_end' => false]);

    app(SubscriptionSync::class)->apply($subscription, $state);

    expect(collect(app(TemplateMail::class)->sent)->pluck('template')->all())->toBe(['customer.subscription_resumed']);
});

it('FR-E1 mails the customer when the plan changes', function () {
    [$provider, $plan, $price] = liveStorefront();
    $other = Price::factory()->create(['provider_id' => $provider->id, 'plan_id' => $plan->id, 'stripe_price_id' => 'price_other', 'amount' => 9900, 'currency' => $price->currency, 'interval' => $price->interval]);
    $subscription = subscribe(customerSession(), $provider, $plan, $price);
    $state = array_merge(fakeStripe()->subscriptions[$subscription->stripe_subscription_id], ['price_id' => $other->stripe_price_id]);

    app(SubscriptionSync::class)->apply($subscription, $state);

    expect(collect(app(TemplateMail::class)->sent)->pluck('template')->all())->toBe(['customer.plan_changed']);
});

it('FR-E1 a mail failure never breaks the subscription sync', function () {
    [$provider, $plan, $price] = liveStorefront();
    $subscription = subscribe(customerSession(), $provider, $plan, $price, ['status' => 'incomplete']);
    app()->instance(TemplateMail::class, new class implements TemplateMail
    {
        public function send(string $template, string $to, array $variables, ?string $providerId = null): void
        {
            throw new RuntimeException('smtp down');
        }
    });
    $state = array_merge(fakeStripe()->subscriptions[$subscription->stripe_subscription_id], ['status' => 'active']);

    expect(app(SubscriptionSync::class)->apply($subscription, $state))->toBeTrue()
        ->and($subscription->fresh()->status->value)->toBe('active');
});
