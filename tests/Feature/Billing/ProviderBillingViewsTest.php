<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Provider;
use App\Models\ProviderCustomer;
use App\Models\Subscription;
use App\Models\User;

/** A signed-in provider owner with a plan. Returns [provider, plan, price]. */
function sellingProvider(): array
{
    [, $provider] = providerOwnerSession(['slug' => 'acme', 'stripe_account_id' => 'acct_acme', 'stripe_status' => 'enabled', 'currency' => 'usd']);
    $plan = Plan::factory()->create(['provider_id' => $provider->id, 'status' => 'active', 'name' => 'Pro']);
    $price = Price::factory()->create(['provider_id' => $provider->id, 'plan_id' => $plan->id, 'amount' => 1900, 'stripe_price_id' => 'price_pro']);

    return [$provider, $plan, $price];
}

function subscriber(Provider $provider, Plan $plan, Price $price, array $user = [], array $subscription = []): Subscription
{
    return subscribe(User::factory()->create($user), $provider, $plan, $price, $subscription);
}

it('PRD 3.5 a provider lists only the customers who actually subscribed to them', function () {
    [$provider, $plan, $price] = sellingProvider();
    subscriber($provider, $plan, $price, ['name' => 'Olive Subscriber', 'email' => 'olive@example.com']);
    User::factory()->create(['name' => 'Just Browsing']); // has an account, never subscribed
    $elsewhere = Provider::factory()->create(['slug' => 'other']);
    subscriber($elsewhere, Plan::factory()->create(['provider_id' => $elsewhere->id]), Price::factory()->create(['provider_id' => $elsewhere->id, 'plan_id' => Plan::factory()->create(['provider_id' => $elsewhere->id])->id]), ['name' => 'Rival Customer']);

    $response = $this->getJson('/api/v1/provider/subscribers')->assertOk()->assertJsonPath('meta.total', 1);

    expect($response->json('data.0'))->toMatchArray(['name' => 'Olive Subscriber', 'email' => 'olive@example.com', 'subscriptions_count' => 1, 'active_subscriptions' => 1])
        ->and($response->json('data.0'))->not->toHaveKeys(['user_id', 'password', 'roles']);
    expect($response->getContent())->not->toContain('Just Browsing')->not->toContain('Rival Customer');
});

it('FR-PD3 searches subscribers by name or email', function () {
    [$provider, $plan, $price] = sellingProvider();
    subscriber($provider, $plan, $price, ['name' => 'Olive Grove', 'email' => 'olive@example.com']);
    subscriber($provider, $plan, $price, ['name' => 'Sam Rivers', 'email' => 'sam@example.com']);

    $this->getJson('/api/v1/provider/subscribers?search=olive')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Olive Grove');
    $this->getJson('/api/v1/provider/subscribers?search=SAM@')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Sam Rivers');
});

it('FR-PD3 lists subscriptions with filters and the subscriber\'s name', function () {
    [$provider, $plan, $price] = sellingProvider();
    subscriber($provider, $plan, $price, ['name' => 'Olive'], ['status' => 'active']);
    subscriber($provider, $plan, $price, ['name' => 'Sam'], ['status' => 'past_due']);
    subscriber($provider, $plan, $price, ['name' => 'Kim'], ['status' => 'canceled']);

    $this->getJson('/api/v1/provider/subscriptions')->assertOk()->assertJsonPath('meta.total', 3);
    $this->getJson('/api/v1/provider/subscriptions?status=past_due')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.customer.name', 'Sam')->assertJsonPath('data.0.plan.name', 'Pro');
    $this->getJson('/api/v1/provider/subscriptions?search=kim')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/provider/subscriptions?plan_id='.$plan->id)->assertJsonPath('meta.total', 3);
    $this->getJson('/api/v1/provider/subscriptions?status=nonsense')->assertStatus(422);
});

it('FR-PD3 shows a subscriber\'s history: invoices and payments', function () {
    [$provider, $plan, $price] = sellingProvider();
    $subscription = subscriber($provider, $plan, $price);
    Invoice::factory()->create(['provider_id' => $provider->id, 'subscription_id' => $subscription->id, 'number' => 'ACME-1']);
    Payment::factory()->create(['provider_id' => $provider->id, 'subscription_id' => $subscription->id, 'status' => 'failed', 'failure_code' => 'insufficient_funds']);

    $this->getJson("/api/v1/provider/subscriptions/{$subscription->id}")
        ->assertOk()
        ->assertJsonPath('data.invoices.0.number', 'ACME-1')
        ->assertJsonPath('data.payments.0.failure_code', 'insufficient_funds');
});

it('FR-MT a provider cannot open another provider\'s subscription', function () {
    sellingProvider();
    $other = Provider::factory()->create(['slug' => 'other']);
    $foreign = Subscription::factory()->create(['provider_id' => $other->id, 'provider_customer_id' => ProviderCustomer::factory()->create(['provider_id' => $other->id])->id, 'plan_id' => Plan::factory()->create(['provider_id' => $other->id])->id, 'price_id' => Price::factory()->create(['provider_id' => $other->id])->id]);

    $this->getJson("/api/v1/provider/subscriptions/{$foreign->id}")->assertNotFound();
    $this->postJson("/api/v1/provider/subscriptions/{$foreign->id}/cancel")->assertNotFound();
});

it('FR-PD3 a provider can cancel a subscriber, at period end or now', function () {
    [$provider, $plan, $price] = sellingProvider();
    $subscription = subscriber($provider, $plan, $price);

    $this->postJson("/api/v1/provider/subscriptions/{$subscription->id}/cancel")->assertOk()->assertJsonPath('data.cancel_at_period_end', true);
    $this->postJson("/api/v1/provider/subscriptions/{$subscription->id}/cancel", ['when' => 'now'])->assertOk()->assertJsonPath('data.status', 'canceled');
});

it('FR-PD4 lists invoices and payments of this provider only', function () {
    [$provider, $plan, $price] = sellingProvider();
    $subscription = subscriber($provider, $plan, $price, ['name' => 'Olive']);
    Invoice::factory()->create(['provider_id' => $provider->id, 'subscription_id' => $subscription->id, 'provider_customer_id' => $subscription->provider_customer_id]);
    Payment::factory()->create(['provider_id' => $provider->id, 'subscription_id' => $subscription->id, 'amount' => 1900, 'amount_refunded' => 400, 'status' => 'partially_refunded']);
    $other = Provider::factory()->create(['slug' => 'other']);
    Invoice::factory()->create(['provider_id' => $other->id]);
    Payment::factory()->create(['provider_id' => $other->id]);

    $this->getJson('/api/v1/provider/invoices')->assertOk()->assertJsonPath('meta.total', 1);
    $payments = $this->getJson('/api/v1/provider/payments')->assertOk()->assertJsonPath('meta.total', 1);
    expect($payments->json('data.0'))->toMatchArray(['amount' => 1900, 'amount_refunded' => 400, 'refundable' => 1500, 'status' => 'partially_refunded']);
    $this->getJson('/api/v1/provider/payments?status=failed')->assertJsonPath('meta.total', 0);
});

it('FR-PD1 summarises the business: MRR, subscribers, churn, failures and renewals', function () {
    [$provider, $plan, $price] = sellingProvider();
    $yearly = $plan->prices()->create(['provider_id' => $provider->id, 'amount' => 12000, 'currency' => 'usd', 'interval' => 'year', 'stripe_price_id' => 'price_year']);

    subscriber($provider, $plan, $price, [], ['status' => 'active', 'current_period_end' => now()->addDays(3)]);                          // 1900/month, renews soon
    subscriber($provider, $plan, $yearly, [], ['status' => 'active', 'interval' => 'year', 'amount' => 12000]);                           // 1000/month
    subscriber($provider, $plan, $price, [], ['status' => 'trialing']);                                                                    // access, but no revenue yet
    subscriber($provider, $plan, $price, [], ['status' => 'canceled', 'ended_at' => now()->subDays(5)]);                                    // churned recently
    subscriber($provider, $plan, $price, [], ['status' => 'active', 'cancel_at_period_end' => true, 'current_period_end' => now()->addDays(2)]); // ending, not a renewal
    $subscription = Subscription::first();
    Payment::factory()->create(['provider_id' => $provider->id, 'subscription_id' => $subscription->id, 'status' => 'failed']);
    Payment::factory()->create(['provider_id' => $provider->id, 'subscription_id' => $subscription->id, 'status' => 'failed', 'created_at' => now()->subDays(60)]);

    $this->getJson('/api/v1/provider/overview')->assertOk()->assertJson(['data' => [
        'currency' => 'usd',
        'mrr' => 1900 + 1000 + 1900, // the cancel-at-period-end subscription still pays until it ends
        'active_subscribers' => 4,
        'new_30d' => 5,
        'churned_30d' => 1,
        'failed_payments_30d' => 1,
        'upcoming_renewals_7d' => 1,
    ]]);
});

it('FR-PD1 the overview is empty, not an error, for a brand new provider', function () {
    sellingProvider();

    $this->getJson('/api/v1/provider/overview')->assertOk()->assertJsonPath('data.mrr', 0)->assertJsonPath('data.active_subscribers', 0);
});
