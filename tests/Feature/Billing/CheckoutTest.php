<?php

use App\Enums\ProviderStatus;
use App\Models\CommissionRule;
use App\Models\Plan;
use App\Models\Provider;
use App\Models\ProviderCustomer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Settings\SettingsService;
use App\Services\Stripe\StripeGateway;
use Tests\Support\FakeStripeGateway;

it('FR-C1 a signed-in customer joins in one call, without registering again', function () {
    [$provider, $plan, $price] = liveStorefront();
    $user = customerSession();
    $users = User::count();

    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id])
        ->assertCreated()
        ->assertJsonPath('data.subscription.status', 'incomplete')
        ->assertJsonPath('data.subscription.plan.name', $plan->name)
        ->assertJsonPath('data.subscription.amount', 2000)
        ->assertJsonPath('data.client_secret_type', 'payment')
        ->assertJson(fn ($json) => $json->has('data.client_secret')->etc());

    expect(User::count())->toBe($users);

    $link = ProviderCustomer::sole();
    expect($link->provider_id)->toBe($provider->id)->and($link->user_id)->toBe($user->id)->and($link->stripe_customer_id)->toStartWith('cus_fake');

    $subscription = Subscription::sole();
    expect($subscription->provider_customer_id)->toBe($link->id)
        ->and($subscription->price_id)->toBe($price->id)
        ->and($subscription->stripe_subscription_id)->toStartWith('sub_fake');
});

it('FR-C1 tells the browser which connected account and publishable key to confirm payment with', function () {
    [, , $price] = liveStorefront();
    customerSession();
    app(SettingsService::class)->set('stripe.publishable_key', 'pk_test_abc');

    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id])
        ->assertCreated()
        ->assertJsonPath('data.stripe_account', 'acct_acme')
        ->assertJsonPath('data.publishable_key', 'pk_test_abc');
});

it('FR-C1 creates the Stripe customer and subscription on the provider\'s connected account, with idempotency keys', function () {
    [, , $price] = liveStorefront();
    $user = customerSession(['email' => 'olive@example.com', 'name' => 'Olive']);

    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id])->assertCreated();

    $customer = fakeStripe()->callsTo('createCustomer')[0];
    expect($customer['account'])->toBe('acct_acme')
        ->and($customer['params'])->toMatchArray(['email' => 'olive@example.com', 'name' => 'Olive'])
        ->and($customer['idempotency_key'])->not->toBeEmpty();

    $subscription = fakeStripe()->callsTo('createSubscription')[0];
    expect($subscription['account'])->toBe('acct_acme')
        ->and($subscription['params']['items'])->toBe([['price' => $price->stripe_price_id]])
        ->and($subscription['params']['payment_behavior'])->toBe('default_incomplete')
        ->and($subscription['params']['payment_settings'])->toBe(['save_default_payment_method' => 'on_subscription'])
        ->and($subscription['params']['metadata'])->toMatchArray(['subscription_id' => Subscription::sole()->id, 'provider_id' => $price->provider_id])
        ->and($subscription['idempotency_key'])->toContain(Subscription::sole()->id);
});

it('FR-CM3 passes the platform fee percent to Stripe when one is configured', function () {
    [, , $price] = liveStorefront();
    customerSession();
    CommissionRule::factory()->create(['percent' => 10]);

    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id])->assertCreated();

    expect(fakeStripe()->callsTo('createSubscription')[0]['params']['application_fee_percent'])->toEqual(10);
});

it('FR-C1 sends no fee while the commission is zero', function () {
    [, , $price] = liveStorefront();
    customerSession();

    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id])->assertCreated();

    expect(fakeStripe()->callsTo('createSubscription')[0]['params'])->not->toHaveKey('application_fee_percent');
});

it('FR-C1 a plan with a trial starts trialing and asks for a card through a setup intent', function () {
    [, , $price] = liveStorefront(plan: ['trial_days' => 14]);
    customerSession();

    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id])
        ->assertCreated()
        ->assertJsonPath('data.subscription.status', 'trialing')
        ->assertJsonPath('data.client_secret_type', 'setup');

    expect(fakeStripe()->callsTo('createSubscription')[0]['params']['trial_period_days'])->toBe(14)
        ->and(Subscription::sole()->trial_ends_at)->not->toBeNull()
        ->and(ProviderCustomer::count())->toBe(1);
});

it('PRD 3.5 merely visiting a storefront never links a customer to the provider', function () {
    liveStorefront();
    customerSession();

    onStorefront('get', '/public/storefront')->assertOk();
    onStorefront('get', '/public/tenant')->assertOk();

    expect(ProviderCustomer::count())->toBe(0)->and(fakeStripe()->callsTo('createCustomer'))->toBe([]);
});

it('FR-C1 requires a signed-in customer', function () {
    [, , $price] = liveStorefront();

    $this->withoutToken();
    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id])->assertUnauthorized();
    expect(ProviderCustomer::count())->toBe(0);
});

it('FR-C1 can only be done from a provider\'s storefront', function () {
    [, , $price] = liveStorefront();
    customerSession();

    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id], 'platform.test')
        ->assertStatus(422)->assertJsonPath('code', 'storefront_required');
});

it('FR-C1 validates the price id', function () {
    liveStorefront();
    customerSession();

    onStorefront('post', '/customer/subscriptions', [])->assertStatus(422)->assertJsonValidationErrors('price_id');
});

it('FR-P9 refuses checkout while the provider is paused', function (ProviderStatus $status) {
    [, , $price] = liveStorefront(['status' => $status]);
    customerSession();

    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id])->assertStatus(409)->assertJsonPath('code', 'checkout_unavailable');
    expect(ProviderCustomer::count())->toBe(0)->and(Subscription::count())->toBe(0);
})->with([ProviderStatus::Inactive, ProviderStatus::Suspended]);

it('FR-P4 refuses checkout until the provider\'s Stripe account is enabled', function () {
    [, , $price] = liveStorefront(['stripe_status' => 'pending']);
    customerSession();

    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id])->assertStatus(409)->assertJsonPath('code', 'checkout_unavailable');
});

it('FR-PL1 only prices of a live plan can be bought', function (array $plan, array $price) {
    [, , $price] = liveStorefront(plan: $plan, price: $price);
    customerSession();

    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id])->assertNotFound();
})->with([
    'draft plan' => [['status' => 'draft'], []],
    'archived plan' => [['status' => 'archived'], []],
    'archived price' => [[], ['status' => 'archived']],
    'price not yet on Stripe' => [[], ['stripe_price_id' => null]],
]);

it('FR-MT another provider\'s price is a 404 on this storefront', function () {
    liveStorefront();
    $other = Provider::factory()->create(['slug' => 'other', 'status' => ProviderStatus::Active, 'stripe_account_id' => 'acct_other', 'stripe_status' => 'enabled']);
    $otherPrice = tap(Plan::factory()->create(['provider_id' => $other->id, 'status' => 'active']), fn ($plan) => $plan)
        ->prices()->create(['provider_id' => $other->id, 'amount' => 900, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_other']);
    customerSession();

    onStorefront('post', '/customer/subscriptions', ['price_id' => $otherPrice->id])->assertNotFound();
});

it('FR-C1 trying again replaces the abandoned attempt and reuses the Stripe customer', function () {
    [, , $price] = liveStorefront();
    customerSession();

    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id])->assertCreated();
    $first = Subscription::sole();
    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id])->assertCreated();

    expect(ProviderCustomer::count())->toBe(1)
        ->and(fakeStripe()->callsTo('createCustomer'))->toHaveCount(1)
        ->and(Subscription::count())->toBe(2)
        ->and($first->fresh()->status->value)->toBe('expired')
        ->and(fakeStripe()->callsTo('cancelSubscription')[0]['subscription'])->toBe($first->stripe_subscription_id);
});

it('FR-C2 refuses a second live subscription to the same plan', function () {
    [, , $price] = liveStorefront();
    customerSession();
    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id])->assertCreated();
    Subscription::sole()->update(['status' => 'active']);

    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id])->assertStatus(409)->assertJsonPath('code', 'already_subscribed');
    expect(Subscription::count())->toBe(1);
});

it('FR-PM7 a Stripe failure leaves no customer link or subscription behind', function () {
    [, , $price] = liveStorefront();
    customerSession();
    $this->app->instance(StripeGateway::class, new class extends FakeStripeGateway
    {
        public function createSubscription(string $accountId, array $params, string $idempotencyKey): array
        {
            throw new RuntimeException('card_declined');
        }
    });

    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id])->assertStatus(500);

    expect(ProviderCustomer::count())->toBe(0)->and(Subscription::count())->toBe(0);
});

it('FR-AD3 a blocked customer cannot check out', function () {
    [, , $price] = liveStorefront();
    $user = customerSession();
    $user->forceFill(['blocked_at' => now()])->save();

    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id])->assertForbidden();
});

it('FR-CM3 sells the subscription at the rate that applies to the provider today and keeps it on the subscription', function () {
    [$provider, , $price] = liveStorefront();
    customerSession();
    CommissionRule::factory()->create(['percent' => 10]);
    CommissionRule::factory()->create(['provider_id' => $provider->id, 'percent' => 7.5]);

    onStorefront('post', '/customer/subscriptions', ['price_id' => $price->id])->assertCreated();

    expect(fakeStripe()->callsTo('createSubscription')[0]['params']['application_fee_percent'])->toEqual(7.5)
        ->and(Subscription::sole()->application_fee_percent)->toBe('7.50');
});
