<?php

use App\Enums\ProviderStatus;
use App\Models\Provider;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Str;

function ownerToken(?User $user = null): string
{
    $user ??= User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $token = loginAs($user)->json('data.access_token');
    app('auth')->forgetGuards();

    return $token;
}

function providerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Acme Fitness',
        'slug' => 'acme',
        'business_category' => 'fitness',
        'country' => 'US',
        'support_email' => 'help@acme.test',
    ], $overrides);
}

it('FR-P1 requires a signed-in user to register a provider', function () {
    $this->postJson('/api/v1/provider/register', providerPayload())->assertUnauthorized();
});

it('FR-P2 auto-approves by default, makes the user the owner and gives them the provider_owner role', function () {
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');

    $this->withToken(ownerToken($user))->postJson('/api/v1/provider/register', providerPayload())
        ->assertCreated()
        ->assertJsonPath('data.slug', 'acme')
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.currency', 'usd');

    $provider = Provider::where('slug', 'acme')->firstOrFail();
    expect(Str::isUuid($provider->id))->toBeTrue()
        ->and($provider->status)->toBe(ProviderStatus::Approved)
        ->and($provider->approved_at)->not->toBeNull()
        ->and($provider->owner()->is($user))->toBeTrue()
        ->and($user->fresh()->hasRole('provider_owner'))->toBeTrue()
        ->and($user->fresh()->hasRole('customer'))->toBeTrue();
});

it('FR-P2 leaves the provider pending when auto-approve is switched off', function () {
    app(SettingsService::class)->set('provider.auto_approve', false);

    $this->withToken(ownerToken())->postJson('/api/v1/provider/register', providerPayload())
        ->assertCreated()->assertJsonPath('data.status', 'pending');

    expect(Provider::first()->approved_at)->toBeNull();
});

it('FR-P1 validates the registration form', function (array $overrides, string $field) {
    $this->withToken(ownerToken())->postJson('/api/v1/provider/register', providerPayload($overrides))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'name required' => [['name' => ''], 'name'],
    'country must be 2 letters' => [['country' => 'USA'], 'country'],
    'category required' => [['business_category' => ''], 'business_category'],
    'support email must be an email' => [['support_email' => 'nope'], 'support_email'],
    'currency outside the admin list' => [['currency' => 'jpy'], 'currency'],
    'slug too short' => [['slug' => 'ab'], 'slug'],
]);

it('FR-PL10 lets the provider pick any currency the admin enabled', function () {
    app(SettingsService::class)->set('provider.allowed_currencies', ['usd', 'cad']);

    $this->withToken(ownerToken())->postJson('/api/v1/provider/register', providerPayload(['currency' => 'cad']))
        ->assertCreated()->assertJsonPath('data.currency', 'cad');
});

it('FR-P1 allows one provider per owner', function () {
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');

    $this->withToken(ownerToken($user))->postJson('/api/v1/provider/register', providerPayload())->assertCreated();
    app('auth')->forgetGuards();
    $this->withToken(ownerToken($user))->postJson('/api/v1/provider/register', providerPayload(['slug' => 'second']))
        ->assertStatus(409)->assertJsonPath('code', 'provider_exists');
});

it('FR-P1 returns the registered provider to its owner', function () {
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $this->withToken(ownerToken($user))->postJson('/api/v1/provider/register', providerPayload())->assertCreated();
    app('auth')->forgetGuards();

    $this->withToken(ownerToken($user))->getJson('/api/v1/provider')
        ->assertOk()->assertJsonPath('data.slug', 'acme');
});
