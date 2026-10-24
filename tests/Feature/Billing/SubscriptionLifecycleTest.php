<?php

use App\Models\Plan;
use App\Models\Provider;
use App\Models\Subscription;
use App\Models\User;

/** [user, provider, plan, price, subscription] for a signed-in customer with an active subscription. */
function activeSubscriber(array $subscription = [], array $plan = [], array $price = []): array
{
    [$provider, $plan, $price] = liveStorefront(plan: $plan, price: $price);
    $user = customerSession();

    return [$user, $provider, $plan, $price, subscribe($user, $provider, $plan, $price, $subscription)];
}

it('FR-C4 cancels at the period end by default and keeps access until then', function () {
    [, , , , $subscription] = activeSubscriber();

    $this->postJson("/api/v1/customer/subscriptions/{$subscription->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.cancel_at_period_end', true)
        ->assertJsonPath('data.has_access', true);

    $call = fakeStripe()->callsTo('updateSubscription')[0];
    expect($call['account'])->toBe('acct_acme')->and($call['params'])->toBe(['cancel_at_period_end' => true])->and($call['idempotency_key'])->toContain($subscription->id);
});

it('FR-C4 can cancel immediately', function () {
    [, , , , $subscription] = activeSubscriber();

    $this->postJson("/api/v1/customer/subscriptions/{$subscription->id}/cancel", ['when' => 'now'])
        ->assertOk()->assertJsonPath('data.status', 'canceled')->assertJsonPath('data.has_access', false);

    expect(fakeStripe()->callsTo('cancelSubscription'))->toHaveCount(1)->and($subscription->fresh()->ended_at)->not->toBeNull();
});

it('FR-C4 resumes a subscription that was set to end', function () {
    [, , , , $subscription] = activeSubscriber(['cancel_at_period_end' => true]);

    $this->postJson("/api/v1/customer/subscriptions/{$subscription->id}/resume")
        ->assertOk()->assertJsonPath('data.cancel_at_period_end', false);

    expect(fakeStripe()->callsTo('updateSubscription')[0]['params'])->toBe(['cancel_at_period_end' => false]);
});

it('FR-C4 refuses to resume one that is not set to end, or to cancel one that is already over', function () {
    [, , , , $subscription] = activeSubscriber();
    $this->postJson("/api/v1/customer/subscriptions/{$subscription->id}/resume")->assertStatus(409)->assertJsonPath('code', 'not_resumable');

    $subscription->update(['status' => 'canceled']);
    $this->postJson("/api/v1/customer/subscriptions/{$subscription->id}/cancel")->assertStatus(409)->assertJsonPath('code', 'not_cancelable');
});

it('FR-C4 validates when to cancel', function () {
    [, , , , $subscription] = activeSubscriber();

    $this->postJson("/api/v1/customer/subscriptions/{$subscription->id}/cancel", ['when' => 'someday'])->assertStatus(422)->assertJsonValidationErrors('when');
});

it('FR-MT a customer cannot touch anyone else\'s subscription', function () {
    [, $provider, $plan, $price] = activeSubscriber();
    $other = User::factory()->create()->assignRole('customer');
    $theirs = subscribe($other, $provider, $plan, $price);

    $this->postJson("/api/v1/customer/subscriptions/{$theirs->id}/cancel")->assertNotFound();
    $this->postJson("/api/v1/customer/subscriptions/{$theirs->id}/resume")->assertNotFound();
    expect($theirs->fresh()->cancel_at_period_end)->toBeFalse();
});

it('FR-C3 previews an upgrade with integer proration', function () {
    [, $provider, $plan, , $subscription] = activeSubscriber();
    $bigger = $plan->prices()->create(['provider_id' => $provider->id, 'amount' => 5000, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_bigger']);

    $response = $this->postJson("/api/v1/customer/subscriptions/{$subscription->id}/change/preview", ['price_id' => $bigger->id])->assertOk();

    // Half the period is left (15 of 30 days), so credit 1000 and charge 2500.
    expect($response->json('data'))->toMatchArray(['timing' => 'immediate', 'credit' => 1000, 'charge' => 2500, 'net' => 1500, 'currency' => 'usd']);
});

it('FR-C3 upgrades immediately with prorations and moves the local plan', function () {
    [, $provider, $plan, , $subscription] = activeSubscriber();
    $bigPlan = Plan::factory()->create(['provider_id' => $provider->id, 'status' => 'active', 'name' => 'Max']);
    $bigger = $bigPlan->prices()->create(['provider_id' => $provider->id, 'amount' => 5000, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_bigger']);

    $this->postJson("/api/v1/customer/subscriptions/{$subscription->id}/change", ['price_id' => $bigger->id])
        ->assertOk()->assertJsonPath('data.amount', 5000)->assertJsonPath('data.plan.name', 'Max');

    $call = fakeStripe()->callsTo('updateSubscription')[0];
    expect($call['params']['items'][0]['price'])->toBe('price_bigger')->and($call['params']['items'][0]['id'])->toStartWith('si_')
        ->and($call['params']['proration_behavior'])->toBe('create_prorations')
        ->and($subscription->fresh()->price_id)->toBe($bigger->id)->and($subscription->fresh()->plan_id)->toBe($bigPlan->id);
});

it('FR-C3 a change on the next cycle does not prorate', function () {
    [, $provider, $plan, , $subscription] = activeSubscriber();
    $cheaper = $plan->prices()->create(['provider_id' => $provider->id, 'amount' => 900, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_cheaper']);

    $this->postJson("/api/v1/customer/subscriptions/{$subscription->id}/change/preview", ['price_id' => $cheaper->id, 'timing' => 'next_cycle'])
        ->assertOk()->assertJsonPath('data.net', 0)->assertJsonPath('data.timing', 'next_cycle');

    $this->postJson("/api/v1/customer/subscriptions/{$subscription->id}/change", ['price_id' => $cheaper->id, 'timing' => 'next_cycle'])->assertOk();

    expect(fakeStripe()->callsTo('updateSubscription')[0]['params']['proration_behavior'])->toBe('none');
});

it('FR-C3 refuses impossible changes', function (callable $prepare, string $code) {
    [, $provider, $plan, $price, $subscription] = activeSubscriber();
    $target = $prepare($provider, $plan, $price, $subscription);

    $this->postJson("/api/v1/customer/subscriptions/{$subscription->id}/change", ['price_id' => $target->id])->assertStatus(409)->assertJsonPath('code', $code);
})->with([
    'same price' => [fn ($provider, $plan, $price) => $price, 'same_price'],
    'other currency' => [fn ($provider, $plan) => $plan->prices()->create(['provider_id' => $provider->id, 'amount' => 900, 'currency' => 'eur', 'interval' => 'month', 'stripe_price_id' => 'price_eur']), 'currency_mismatch'],
    'subscription not live' => [function ($provider, $plan, $price, $subscription) {
        $subscription->update(['status' => 'canceled']);

        return $plan->prices()->create(['provider_id' => $provider->id, 'amount' => 900, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_x']);
    }, 'not_changeable'],
]);

it('FR-C3 cannot switch to another provider\'s price or an archived one', function () {
    [, $provider, $plan, , $subscription] = activeSubscriber();
    $archived = $plan->prices()->create(['provider_id' => $provider->id, 'amount' => 900, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_old', 'status' => 'archived']);
    [, , $foreign] = (function () {
        $other = Provider::factory()->create(['slug' => 'other', 'stripe_account_id' => 'acct_other', 'stripe_status' => 'enabled']);
        $plan = Plan::factory()->create(['provider_id' => $other->id, 'status' => 'active']);

        return [$other, $plan, $plan->prices()->create(['provider_id' => $other->id, 'amount' => 900, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_foreign'])];
    })();

    $this->postJson("/api/v1/customer/subscriptions/{$subscription->id}/change", ['price_id' => $archived->id])->assertNotFound();
    $this->postJson("/api/v1/customer/subscriptions/{$subscription->id}/change", ['price_id' => $foreign->id])->assertNotFound();
});

it('FR-C8 tells a subscriber whether they currently have access', function (string $status, bool $access) {
    [, , , , $subscription] = activeSubscriber(['status' => $status]);

    $this->getJson("/api/v1/subscriptions/{$subscription->id}/access")
        ->assertOk()
        ->assertJsonPath('data.subscription_id', $subscription->id)
        ->assertJsonPath('data.status', $status)
        ->assertJsonPath('data.has_access', $access);
})->with([
    ['active', true], ['trialing', true], ['past_due', true], ['grace', true],
    ['paused', false], ['canceled', false], ['expired', false], ['incomplete', false],
]);

it('FR-C8 a canceled-at-period-end subscription keeps access and reports when it ends', function () {
    [, , , , $subscription] = activeSubscriber(['cancel_at_period_end' => true]);

    $this->getJson("/api/v1/subscriptions/{$subscription->id}/access")
        ->assertOk()->assertJsonPath('data.has_access', true)->assertJsonPath('data.cancel_at_period_end', true)
        ->assertJson(fn ($json) => $json->has('data.current_period_end')->etc());
});

it('FR-C8 access can be read by the provider and admins, but by no one else', function () {
    [, $provider, $plan, $price, $subscription] = activeSubscriber();

    // The provider's owner.
    $owner = User::factory()->create(['password' => 'Password123!'])->assignRole('customer')->assignRole('provider_owner');
    $provider->members()->create(['user_id' => $owner->id, 'role' => 'owner']);
    $this->withToken(loginAs($owner)->json('data.access_token'));
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/subscriptions/{$subscription->id}/access")->assertOk();

    // An admin.
    $admin = User::factory()->create(['password' => 'Password123!'])->assignRole('admin');
    $this->withToken(loginAs($admin)->json('data.access_token'));
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/subscriptions/{$subscription->id}/access")->assertOk();

    // A stranger, and a guest.
    customerSession();
    $this->getJson("/api/v1/subscriptions/{$subscription->id}/access")->assertNotFound();
    asGuest();
    $this->getJson("/api/v1/subscriptions/{$subscription->id}/access")->assertUnauthorized();
});
