<?php

use App\Enums\ProviderStatus;
use App\Models\Provider;
use App\Models\User;
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
