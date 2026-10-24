<?php

use App\Models\Activity;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Provider;
use App\Models\Subscription;
use App\Models\User;

/** Two providers with one subscriber each. Returns [acme subscription, other subscription]. */
function twoProvidersWithSubscribers(): array
{
    [$acme, $plan, $price] = liveStorefront();
    $first = subscribe(User::factory()->create(['name' => 'Olive']), $acme, $plan, $price);

    $other = Provider::factory()->create(['slug' => 'other', 'stripe_account_id' => 'acct_other', 'stripe_status' => 'enabled', 'name' => 'Other Co']);
    $otherPlan = Plan::factory()->create(['provider_id' => $other->id, 'status' => 'active']);
    $second = subscribe(User::factory()->create(['name' => 'Sam']), $other, $otherPlan, Price::factory()->create(['provider_id' => $other->id, 'plan_id' => $otherPlan->id, 'stripe_price_id' => 'price_other']), ['status' => 'past_due']);

    return [$first, $second];
}

it('FR-AD6 lists subscriptions across all providers with filters', function () {
    twoProvidersWithSubscribers();
    adminSession();

    $this->getJson('/api/v1/admin/subscriptions')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/admin/subscriptions?status=past_due')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.customer.name', 'Sam')->assertJsonPath('data.0.provider.slug', 'other');
    $this->getJson('/api/v1/admin/subscriptions?search=olive')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/admin/subscriptions?provider_id='.Provider::where('slug', 'acme')->value('id'))->assertJsonPath('meta.total', 1);
});

it('FR-AD6 lists invoices across providers', function () {
    [$first, $second] = twoProvidersWithSubscribers();
    Invoice::factory()->create(['provider_id' => $first->provider_id, 'subscription_id' => $first->id, 'provider_customer_id' => $first->provider_customer_id, 'number' => 'A-1']);
    Invoice::factory()->create(['provider_id' => $second->provider_id, 'subscription_id' => $second->id, 'provider_customer_id' => $second->provider_customer_id, 'number' => 'O-1', 'status' => 'open']);
    adminSession();

    $this->getJson('/api/v1/admin/invoices')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/admin/invoices?status=open')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.number', 'O-1')->assertJsonPath('data.0.provider.slug', 'other');
});

it('FR-AD6 an admin can cancel any subscription, and it is audited', function () {
    [, $second] = twoProvidersWithSubscribers();
    $admin = adminSession();

    $this->postJson("/api/v1/admin/subscriptions/{$second->id}/cancel", ['when' => 'now'])->assertOk()->assertJsonPath('data.status', 'canceled');

    expect(fakeStripe()->callsTo('cancelSubscription')[0]['account'])->toBe('acct_other');
    $log = Activity::where('description', 'subscription.canceled')->sole();
    expect($log->causer_id)->toBe($admin->id)->and($log->subject_id)->toBe($second->id);
});

it('FR-AD6 these views are for admins only', function () {
    twoProvidersWithSubscribers();
    customerSession();

    $this->getJson('/api/v1/admin/subscriptions')->assertForbidden();
    $this->getJson('/api/v1/admin/invoices')->assertForbidden();
    $this->postJson('/api/v1/admin/subscriptions/'.Subscription::first()->id.'/cancel')->assertForbidden();
});
