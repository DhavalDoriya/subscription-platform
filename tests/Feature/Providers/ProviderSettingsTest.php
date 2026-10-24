<?php

use App\Enums\ProviderStatus;
use App\Models\Activity;
use App\Models\Provider;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;

function ownerWithProvider(array $provider = []): array
{
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole('customer')->assignRole('provider_owner');
    $model = Provider::factory()->create(array_merge(['status' => ProviderStatus::Approved], $provider));
    $model->members()->create(['user_id' => $user->id, 'role' => 'owner']);
    test()->withToken(loginAs($user)->json('data.access_token'));
    app('auth')->forgetGuards();

    return [$user, $model];
}

it('FR-P7 lets the owner update contact, timezone, branding and legal links', function () {
    [$user, $provider] = ownerWithProvider(['slug' => 'acme']);

    $this->patchJson('/api/v1/provider', [
        'name' => 'Acme Gym',
        'support_email' => 'support@acme.test',
        'timezone' => 'Europe/Berlin',
        'branding' => ['primary_color' => '#3B5BDB'],
        'legal' => ['terms_url' => 'https://acme.test/terms', 'privacy_url' => 'https://acme.test/privacy', 'refund_url' => null],
    ])->assertOk()
        ->assertJsonPath('data.name', 'Acme Gym')
        ->assertJsonPath('data.timezone', 'Europe/Berlin')
        ->assertJsonPath('data.branding.primary_color', '#3b5bdb')
        ->assertJsonPath('data.legal.terms_url', 'https://acme.test/terms');

    expect($provider->fresh()->settings['legal']['privacy_url'])->toBe('https://acme.test/privacy')
        ->and(Activity::where('description', 'provider.updated')->first()->causer_id)->toBe($user->id);
});

it('FR-P3 never lets the owner change the slug, status or Stripe fields', function () {
    [, $provider] = ownerWithProvider(['slug' => 'acme', 'status' => ProviderStatus::Approved]);

    $this->patchJson('/api/v1/provider', ['slug' => 'hijack', 'status' => 'active', 'stripe_account_id' => 'acct_x', 'name' => 'Same'])->assertOk();

    $fresh = $provider->fresh();
    expect($fresh->slug)->toBe('acme')->and($fresh->status)->toBe(ProviderStatus::Approved)->and($fresh->stripe_account_id)->toBeNull();
});

it('FR-P7 validates the settings', function (array $payload, string $field) {
    ownerWithProvider();

    $this->patchJson('/api/v1/provider', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'timezone' => [['timezone' => 'Mars/Base'], 'timezone'],
    'colour' => [['branding' => ['primary_color' => 'blue']], 'branding.primary_color'],
    'terms url' => [['legal' => ['terms_url' => 'javascript:alert(1)']], 'legal.terms_url'],
    'email' => [['support_email' => 'nope'], 'support_email'],
    'currency outside the admin list' => [['currency' => 'jpy'], 'currency'],
]);

it('FR-PL10 lets the owner pick any enabled currency, case-insensitively', function () {
    app(SettingsService::class)->set('provider.allowed_currencies', ['usd', 'cad']);
    ownerWithProvider(['currency' => 'usd']);

    $this->patchJson('/api/v1/provider', ['currency' => 'CAD'])->assertOk()->assertJsonPath('data.currency', 'cad');
});

it('FR-P1 treats the registration currency case-insensitively too', function () {
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $this->withToken(loginAs($user)->json('data.access_token'))->postJson('/api/v1/provider/register', [
        'name' => 'Acme', 'slug' => 'acme', 'business_category' => 'other', 'country' => 'us', 'support_email' => 'a@b.test', 'currency' => 'EUR',
    ])->assertCreated()->assertJsonPath('data.currency', 'eur')->assertJsonPath('data.country', 'US');
});

it('FR-P7 only lets a provider member use these endpoints', function () {
    $stranger = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $this->withToken(loginAs($stranger)->json('data.access_token'));

    $this->patchJson('/api/v1/provider', ['name' => 'X'])->assertNotFound()->assertJsonPath('code', 'provider_not_found');
});

it('FR-P7 returns the provider with branding and legal defaults', function () {
    ownerWithProvider();

    $this->getJson('/api/v1/provider')->assertOk()
        ->assertJsonPath('data.branding.primary_color', null)
        ->assertJsonPath('data.legal.terms_url', null);
});

it('FR-AD2 lists providers without a query per row', function () {
    foreach (range(1, 12) as $i) {
        $provider = Provider::factory()->create();
        $provider->members()->create(['user_id' => User::factory()->create()->id, 'role' => 'owner']);
    }
    $admin = User::factory()->create(['password' => 'Password123!'])->assignRole('admin');
    $this->withToken(loginAs($admin)->json('data.access_token'));
    app('auth')->forgetGuards();

    DB::enableQueryLog();
    $this->getJson('/api/v1/admin/providers?per_page=12')->assertOk()->assertJsonCount(12, 'data');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(10);
});

it('FR-P1 exposes only what a signup form needs through the public config', function () {
    app(SettingsService::class)->set('provider.allowed_currencies', ['usd', 'cad']);
    app(SettingsService::class)->set('stripe.secret_key', 'sk_test_hidden');

    $response = $this->getJson('/api/v1/public/config')->assertOk()
        ->assertJsonPath('data.allowed_currencies', ['usd', 'cad'])
        ->assertJsonPath('data.default_currency', 'usd')
        ->assertJsonPath('data.platform_name', 'Billing Platform')
        ->assertJsonPath('data.base_domain', config('app.base_domain'));

    expect($response->getContent())->not->toContain('sk_test_hidden');
});

it('FR-PM9 lets the owner switch Stripe Tax on only when the platform offers it', function () {
    ownerWithProvider();

    $this->patchJson('/api/v1/provider', ['tax_enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('tax_enabled');

    app(SettingsService::class)->set('stripe.tax_enabled', true);
    app('auth')->forgetGuards();
    $this->patchJson('/api/v1/provider', ['tax_enabled' => true])->assertOk()->assertJsonPath('data.tax_enabled', true);
    $this->patchJson('/api/v1/provider', ['tax_enabled' => false])->assertOk()->assertJsonPath('data.tax_enabled', false);
});

it('FR-PL10 tells price forms which intervals and amount bounds the admin allows', function () {
    $this->getJson('/api/v1/public/config')->assertOk()
        ->assertJsonPath('data.allowed_intervals', ['month', 'year'])->assertJsonPath('data.min_price', 100)->assertJsonPath('data.max_price', 1000000);
});

it('FR-PM9 tells signup and settings forms whether tax can be enabled', function () {
    $this->getJson('/api/v1/public/config')->assertJsonPath('data.tax_available', false);

    app(SettingsService::class)->set('stripe.tax_enabled', true);
    $this->getJson('/api/v1/public/config')->assertJsonPath('data.tax_available', true);
});
