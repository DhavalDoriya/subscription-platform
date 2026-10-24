<?php

use App\Models\Provider;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Testing\TestResponse;

function registerSlug(string $slug): TestResponse
{
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $token = loginAs($user)->json('data.access_token');
    app('auth')->forgetGuards();

    return test()->withToken($token)->postJson('/api/v1/provider/register', [
        'name' => 'Brand', 'slug' => $slug, 'business_category' => 'other', 'country' => 'US', 'support_email' => 'a@b.test',
    ]);
}

it('FR-P3 rejects malformed slugs', function (string $slug) {
    registerSlug($slug)->assertUnprocessable()->assertJsonValidationErrors('slug');
})->with(['ab', str_repeat('a', 41), 'Has-Upper', 'has_underscore', '-leading', 'trailing-', 'has space', 'dot.ted', 'ünicode']);

it('FR-P3 accepts well-formed slugs', function (string $slug) {
    registerSlug($slug)->assertCreated();
})->with(['acme', 'acme-co', 'a1b', 'a-b-c-1', 'x23']);

it('FR-P3 blocks the built-in reserved subdomains', function (string $slug) {
    registerSlug($slug)->assertUnprocessable()->assertJsonValidationErrors('slug');
})->with(['www', 'admin', 'api', 'app', 'mail', 'dashboard', 'account']);

it('FR-P3 blocks slugs the admin reserved in settings', function () {
    app(SettingsService::class)->set('provider.reserved_slugs', ['secret']);

    registerSlug('secret')->assertUnprocessable()->assertJsonValidationErrors('slug');
});

it('FR-P3 keeps slugs unique, including those of deleted providers', function () {
    registerSlug('acme')->assertCreated();
    registerSlug('acme')->assertUnprocessable()->assertJsonValidationErrors('slug');

    Provider::where('slug', 'acme')->first()->delete();
    registerSlug('acme')->assertUnprocessable()->assertJsonValidationErrors('slug');
});

it('FR-P1 answers slug availability with a reason, for anyone', function () {
    registerSlug('taken')->assertCreated();

    $this->getJson('/api/v1/public/slugs/free-one/availability')->assertOk()->assertJsonPath('data', ['available' => true, 'reason' => null]);
    $this->getJson('/api/v1/public/slugs/taken/availability')->assertOk()->assertJsonPath('data', ['available' => false, 'reason' => 'taken']);
    $this->getJson('/api/v1/public/slugs/www/availability')->assertOk()->assertJsonPath('data', ['available' => false, 'reason' => 'reserved']);
    $this->getJson('/api/v1/public/slugs/Bad_Slug/availability')->assertOk()->assertJsonPath('data', ['available' => false, 'reason' => 'invalid']);
});
