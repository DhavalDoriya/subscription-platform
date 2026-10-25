<?php

use App\Enums\ProviderStatus;
use App\Models\Provider;
use App\Models\User;
use App\Services\Settings\SettingsService;

function stripeOwner(array $provider = []): array
{
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole('customer')->assignRole('provider_owner');
    $model = Provider::factory()->create(array_merge(['status' => ProviderStatus::Approved, 'country' => 'US', 'stripe_status' => 'not_started'], $provider));
    $model->members()->create(['user_id' => $user->id, 'role' => 'owner']);
    test()->withToken(loginAs($user)->json('data.access_token'));
    app('auth')->forgetGuards();

    return [$user, $model];
}

it('FR-PM1 creates an Express account on the first onboarding call and returns Stripe\'s hosted link', function () {
    [, $provider] = stripeOwner(['support_email' => 'help@acme.test', 'name' => 'Acme']);

    $this->postJson('/api/v1/provider/stripe/onboarding')
        ->assertOk()->assertJsonPath('data.url', fn ($url) => str_starts_with($url, 'https://connect.stripe.test/onboarding/acct_fake'));

    $create = fakeStripe()->callsTo('createExpressAccount')[0];
    expect($create['params'])->toMatchArray(['type' => 'express', 'country' => 'US', 'email' => 'help@acme.test'])
        ->and($create['params']['business_profile']['name'])->toBe('Acme')
        ->and($create['params']['metadata']['provider_id'])->toBe($provider->id)
        ->and($create['idempotency_key'])->toBe("provider-{$provider->id}-express-account")
        ->and($provider->fresh()->stripe_account_id)->toStartWith('acct_fake')
        ->and($provider->fresh()->stripe_status)->toBe('pending');
});

it('FR-PM1 reuses the account on later calls and builds return links from our own frontend URL', function () {
    stripeOwner();

    $this->postJson('/api/v1/provider/stripe/onboarding')->assertOk();
    $this->postJson('/api/v1/provider/stripe/onboarding', ['return_url' => 'https://evil.test'])->assertOk();

    $stripe = fakeStripe();
    expect($stripe->callsTo('createExpressAccount'))->toHaveCount(1)->and($stripe->callsTo('createAccountLink'))->toHaveCount(2);
    $link = $stripe->callsTo('createAccountLink')[1];
    $frontend = rtrim(config('app.frontend_url'), '/');
    expect($link['return_url'])->toBe($frontend.'/dashboard/stripe/return')
        ->and($link['refresh_url'])->toBe($frontend.'/dashboard/stripe/refresh');
});

it('FR-P5 will not start onboarding for a provider that is not approved', function (ProviderStatus $status) {
    stripeOwner(['status' => $status]);

    $this->postJson('/api/v1/provider/stripe/onboarding')->assertStatus(409)->assertJsonPath('code', 'provider_not_approved');
    expect(fakeStripe()->calls)->toBe([]);
})->with([ProviderStatus::Pending, ProviderStatus::Rejected, ProviderStatus::Suspended, ProviderStatus::Inactive]);

it('FR-PM1 reports the stored status, and refreshes from Stripe on request', function () {
    [, $provider] = stripeOwner(['stripe_account_id' => 'acct_known', 'stripe_status' => 'pending']);
    fakeStripe()->setAccount('acct_known', ['details_submitted' => true, 'charges_enabled' => true, 'payouts_enabled' => true]);

    $this->getJson('/api/v1/provider/stripe')->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonMissingPath('data.account_id');
    expect(fakeStripe()->calls)->toBe([]);

    $this->getJson('/api/v1/provider/stripe?refresh=1')->assertOk()
        ->assertJsonPath('data.status', 'enabled')->assertJsonPath('data.charges_enabled', true)->assertJsonPath('data.payouts_enabled', true);
    expect($provider->fresh()->stripe_status)->toBe('enabled')->and($provider->fresh()->status)->toBe(ProviderStatus::Active);
});

it('FR-PD5 gives an enabled provider a login link to their Stripe Express dashboard, and nobody else', function () {
    stripeOwner(['stripe_account_id' => 'acct_ok', 'stripe_status' => 'enabled']);
    $this->postJson('/api/v1/provider/stripe/dashboard')->assertOk()->assertJsonPath('data.url', 'https://dashboard.stripe.test/express/acct_ok');
});

it('FR-PD5 refuses the dashboard link before onboarding exists', function () {
    stripeOwner();
    $this->postJson('/api/v1/provider/stripe/dashboard')->assertStatus(409)->assertJsonPath('code', 'stripe_not_connected');
});

it('FR-PM1 keeps Stripe endpoints for provider members only', function () {
    $stranger = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $this->postJson('/api/v1/provider/stripe/onboarding')->assertUnauthorized();
    $this->withToken(loginAs($stranger)->json('data.access_token'));

    $this->postJson('/api/v1/provider/stripe/onboarding')->assertNotFound();
    $this->getJson('/api/v1/provider/stripe')->assertNotFound();
});

it('FR-AD7 shows admins whether Stripe is configured, the mode, and the webhook URLs to register', function () {
    $admin = User::factory()->create(['password' => 'Password123!'])->assignRole('admin');
    $this->withToken(loginAs($admin)->json('data.access_token'));

    $this->getJson('/api/v1/admin/stripe')->assertOk()
        ->assertJsonPath('data.configured', false)->assertJsonPath('data.mode', null)
        ->assertJsonPath('data.webhook_url', url('/api/v1/webhooks/stripe'))
        ->assertJsonPath('data.connect_webhook_url', url('/api/v1/webhooks/stripe/connect'));

    app(SettingsService::class)->set('stripe.secret_key', 'sk_test_hidden');
    app(SettingsService::class)->set('stripe.webhook_secret', 'whsec_x');
    app('auth')->forgetGuards();
    $response = $this->getJson('/api/v1/admin/stripe')->assertOk()
        ->assertJsonPath('data.configured', true)->assertJsonPath('data.mode', 'test')
        ->assertJsonPath('data.webhook_secret_set', true)->assertJsonPath('data.connect_webhook_secret_set', false);
    expect($response->getContent())->not->toContain('sk_test_hidden');

    app(SettingsService::class)->set('stripe.secret_key', 'sk_live_hidden');
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/admin/stripe')->assertJsonPath('data.mode', 'live');
});

it('FR-PM1 creates a Standard account when the platform is configured for it (e.g. an Indian platform account), without Express-only capabilities', function () {
    config(['services.stripe.account_type' => 'standard']);
    stripeOwner();

    $this->postJson('/api/v1/provider/stripe/onboarding')->assertOk();

    $params = fakeStripe()->callsTo('createExpressAccount')[0]['params'];
    expect($params['type'])->toBe('standard')->and($params)->not->toHaveKey('capabilities');
});

it('FR-PD5 sends a Standard provider to the Stripe dashboard instead of an Express login link', function () {
    config(['services.stripe.account_type' => 'standard']);
    stripeOwner(['stripe_account_id' => 'acct_ok', 'stripe_status' => 'enabled']);

    $this->postJson('/api/v1/provider/stripe/dashboard')->assertOk()->assertJsonPath('data.url', 'https://dashboard.stripe.com');
    expect(fakeStripe()->callsTo('createLoginLink'))->toBeEmpty();
});
