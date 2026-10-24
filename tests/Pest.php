<?php

use App\Enums\ProviderStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Provider;
use App\Models\ProviderCustomer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Mail\StubTemplateMail;
use App\Services\Mail\TemplateMail;
use App\Services\Stripe\StripeGateway;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed(RoleSeeder::class);
        // Never reach the real Stripe API from a test; use fakeStripe() to inspect or steer the fake.
        $this->app->instance(StripeGateway::class, new FakeStripeGateway);
        // Billing tests read what was mailed from the recording stub; the template mailer has its own tests (tests/Feature/Mail).
        $this->app->instance(TemplateMail::class, new StubTemplateMail);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** Log a user in through the API and return the full response (tokens + refresh cookie). */
function loginAs(User $user, string $password = 'Password123!'): TestResponse
{
    return test()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password]);
}

function refreshCookie(TestResponse $response): string
{
    return $response->getCookie('refresh_token', false)->getValue();
}

function fakeStripe(): FakeStripeGateway
{
    return app(StripeGateway::class);
}

/** POST a Stripe webhook the way Stripe sends it: raw JSON signed with `Stripe-Signature: t=..,v1=hmac`. */
function stripeWebhook(string $uri, array $event, ?string $secret = 'whsec_test', ?string $signature = null): TestResponse
{
    $payload = json_encode($event);
    $timestamp = time();
    $signature ??= 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, (string) $secret);

    return test()->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload);
}

function stripeEvent(string $type, array $object, ?string $id = null, ?string $account = null): array
{
    return array_filter([
        'id' => $id ?? 'evt_'.Str::random(14),
        'object' => 'event',
        'api_version' => '2025-01-01',
        'type' => $type,
        'account' => $account,
        'livemode' => false,
        'created' => time(),
        'data' => ['object' => $object],
    ], fn ($v) => $v !== null);
}

/** A signed-in provider owner with a provider (approved by default). Returns [user, provider]. */
function providerOwnerSession(array $provider = []): array
{
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole('customer')->assignRole('provider_owner');
    $model = Provider::factory()->create(array_merge(['status' => ProviderStatus::Approved, 'currency' => 'usd', 'country' => 'US'], $provider));
    $model->members()->create(['user_id' => $user->id, 'role' => 'owner']);
    test()->withToken(loginAs($user)->json('data.access_token'));
    app('auth')->forgetGuards();

    return [$user, $model];
}

/**
 * A live storefront at acme.platform.test: Stripe-enabled provider, one active plan with one synced price.
 * Returns [provider, plan, price]. Pass plan/provider overrides for trials, paused providers and so on.
 */
function liveStorefront(array $provider = [], array $plan = [], array $price = []): array
{
    config(['app.base_domain' => 'platform.test']);

    $provider = Provider::factory()->create(array_merge([
        'slug' => 'acme', 'status' => ProviderStatus::Active, 'currency' => 'usd',
        'stripe_account_id' => 'acct_acme', 'stripe_status' => 'enabled',
    ], $provider));
    $plan = Plan::factory()->create(array_merge(['provider_id' => $provider->id, 'status' => 'active', 'stripe_product_id' => 'prod_'.Str::random(8)], $plan));
    $price = Price::factory()->create(array_merge(['provider_id' => $provider->id, 'plan_id' => $plan->id, 'amount' => 2000, 'currency' => 'usd', 'stripe_price_id' => 'price_'.Str::random(8)], $price));

    return [$provider, $plan, $price];
}

/** A signed-in customer (global account, no provider links). */
function customerSession(array $attributes = []): User
{
    $user = User::factory()->create(array_merge(['password' => 'Password123!'], $attributes))->assignRole('customer');
    test()->withToken(loginAs($user)->json('data.access_token'));
    app('auth')->forgetGuards();

    return $user;
}

/** Calls the API as the browser would from a provider's storefront host. */
function onStorefront(string $method, string $path, array $data = [], string $host = 'acme.platform.test'): TestResponse
{
    return test()->{$method.'Json'}("http://{$host}/api/v1{$path}", $data);
}

/**
 * A customer's subscription as it looks after checkout, including its twin inside the fake Stripe (so lifecycle calls work).
 * Returns the Subscription. Pass subscription overrides such as ['status' => 'trialing'].
 */
function subscribe(User $user, Provider $provider, Plan $plan, Price $price, array $overrides = []): Subscription
{
    $link = ProviderCustomer::firstOrCreate(['provider_id' => $provider->id, 'user_id' => $user->id], ['stripe_customer_id' => 'cus_'.Str::random(8)]);
    $subscription = Subscription::factory()->create(array_merge([
        'provider_id' => $provider->id, 'provider_customer_id' => $link->id, 'plan_id' => $plan->id, 'price_id' => $price->id,
        'status' => 'active', 'amount' => $price->amount, 'currency' => $price->currency, 'interval' => $price->interval,
        'current_period_start' => now()->subDays(15), 'current_period_end' => now()->addDays(15),
    ], $overrides));

    fakeStripe()->subscriptions[$subscription->stripe_subscription_id] = [
        'id' => $subscription->stripe_subscription_id, 'customer_id' => $link->stripe_customer_id, 'status' => $subscription->status->value,
        'price_id' => $price->stripe_price_id, 'item_id' => 'si_'.Str::random(6),
        'current_period_start' => $subscription->current_period_start->timestamp, 'current_period_end' => $subscription->current_period_end->timestamp,
        'trial_end' => null, 'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end, 'canceled_at' => null, 'ended_at' => null,
        'paused' => false, 'latest_invoice_id' => null, 'client_secret' => null, 'client_secret_type' => null,
    ];

    return $subscription;
}

/** Drops the signed-in user completely: the header, the guard's cached user and the JWT library's remembered token. */
function asGuest(): void
{
    test()->withoutToken();
    app('auth')->forgetGuards();
    foreach (['tymon.jwt', 'tymon.jwt.auth'] as $binding) {
        app($binding)->unsetToken();
    }
}

/** A signed-in platform admin (or another role, e.g. 'super_admin'). */
function adminSession(string $role = 'admin'): User
{
    $admin = User::factory()->create(['password' => 'Password123!'])->assignRole($role);
    test()->withToken(loginAs($admin)->json('data.access_token'));
    app('auth')->forgetGuards();

    return $admin;
}

/** Sends a Connect event (carries the connected account id) the way Stripe does; `created` is the event time. */
function connectEvent(string $type, array $object, int $created, ?string $id = null, string $account = 'acct_acme'): TestResponse
{
    $event = stripeEvent($type, $object, $id, $account);
    $event['created'] = $created;

    return stripeWebhook('/api/v1/webhooks/stripe/connect', $event, secret: 'whsec_connect');
}

/** A subscription on the live storefront, still waiting for payment. */
function pendingSubscription(array $overrides = []): Subscription
{
    [$provider, , $price] = liveStorefront();
    $link = ProviderCustomer::factory()->create(['provider_id' => $provider->id, 'stripe_customer_id' => 'cus_1']);

    return Subscription::factory()->create(array_merge([
        'provider_id' => $provider->id, 'provider_customer_id' => $link->id, 'plan_id' => $price->plan_id, 'price_id' => $price->id,
        'status' => 'incomplete', 'amount' => $price->amount, 'stripe_event_at' => null,
    ], $overrides));
}

/** An open invoice of an active subscription on the live demo storefront; Stripe knows it as in_open1. */
function openInvoice(array $subscription = [], array $invoice = []): Invoice
{
    $subscription = pendingSubscription(array_merge(['status' => 'active', 'stripe_subscription_id' => 'sub_open1'], $subscription));
    fakeStripe()->subscriptions['sub_open1'] = [
        'id' => 'sub_open1', 'customer_id' => 'cus_1', 'status' => 'active', 'price_id' => $subscription->price->stripe_price_id, 'item_id' => 'si_1',
        'current_period_start' => now()->timestamp, 'current_period_end' => now()->addMonth()->timestamp, 'trial_end' => null, 'cancel_at_period_end' => false,
        'canceled_at' => null, 'ended_at' => null, 'paused' => false, 'latest_invoice_id' => 'in_open1', 'client_secret' => null, 'client_secret_type' => null,
    ];

    return Invoice::factory()->create(array_merge([
        'provider_id' => $subscription->provider_id, 'subscription_id' => $subscription->id, 'provider_customer_id' => $subscription->provider_customer_id,
        'stripe_invoice_id' => 'in_open1', 'status' => 'open', 'amount_due' => 2000, 'amount_paid' => 0, 'paid_at' => null,
    ], $invoice));
}
