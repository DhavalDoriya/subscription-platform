<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;

function adminToken(string $role = 'admin'): string
{
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole($role);
    $token = loginAs($user)->json('data.access_token');
    app('auth')->forgetGuards();

    return $token;
}

it('FR-AD7 returns the documented default when nothing is stored', function () {
    $settings = app(SettingsService::class);

    expect($settings->get('provider.auto_approve'))->toBeTrue()
        ->and($settings->get('provider.allowed_currencies'))->toBe(['usd', 'eur', 'gbp']);
});

it('FR-AD7 stores and reads typed values, including arrays', function () {
    $settings = app(SettingsService::class);
    $settings->set('provider.auto_approve', false);
    $settings->set('provider.allowed_currencies', ['usd', 'cad']);

    expect($settings->get('provider.auto_approve'))->toBeFalse()
        ->and($settings->get('provider.allowed_currencies'))->toBe(['usd', 'cad']);
});

it('FR-AD7 rejects keys that are not defined', function () {
    app(SettingsService::class)->set('made.up', 'x');
})->throws(InvalidArgumentException::class);

it('FR-AD7 encrypts secret values at rest', function () {
    app(SettingsService::class)->set('stripe.secret_key', 'sk_test_123');

    $raw = DB::table('settings')->where('key', 'stripe.secret_key')->value('value');

    expect($raw)->not->toContain('sk_test_123')
        ->and(app(SettingsService::class)->get('stripe.secret_key'))->toBe('sk_test_123')
        ->and(Setting::where('key', 'stripe.secret_key')->first()->is_encrypted)->toBeTrue();
});

it('FR-AD7 serves reads from cache and refreshes after a write', function () {
    $settings = app(SettingsService::class);
    $settings->set('general.platform_name', 'Acme Billing');
    expect($settings->get('general.platform_name'))->toBe('Acme Billing');

    DB::table('settings')->where('key', 'general.platform_name')->update(['value' => json_encode('Tampered')]);
    expect($settings->get('general.platform_name'))->toBe('Acme Billing'); // cached

    $settings->set('general.platform_name', 'Fresh');
    expect($settings->get('general.platform_name'))->toBe('Fresh');
});

it('FR-AD7 keeps settings behind admin roles', function () {
    $this->getJson('/api/v1/admin/settings')->assertUnauthorized();

    $this->withToken(adminToken('customer'))->getJson('/api/v1/admin/settings')->assertForbidden();
});

it('FR-AD7 lists settings grouped, and never returns a secret value', function () {
    app(SettingsService::class)->set('stripe.secret_key', 'sk_test_123');

    $response = $this->withToken(adminToken())->getJson('/api/v1/admin/settings')->assertOk();

    $response->assertJsonPath('data.provider.auto_approve.value', true)
        ->assertJsonPath('data.stripe.secret_key.is_set', true)
        ->assertJsonMissingPath('data.stripe.secret_key.value');
    expect($response->getContent())->not->toContain('sk_test_123');
});

it('FR-AD7 updates settings with validation', function () {
    $token = adminToken();

    $this->withToken($token)->putJson('/api/v1/admin/settings', ['settings' => ['provider' => ['auto_approve' => false, 'max_plans' => 5]]])
        ->assertOk()->assertJsonPath('data.provider.auto_approve.value', false);
    expect(app(SettingsService::class)->get('provider.max_plans'))->toBe(5);

    app('auth')->forgetGuards();
    $this->withToken($token)->putJson('/api/v1/admin/settings', ['settings' => ['provider' => ['max_plans' => 'lots']]])
        ->assertUnprocessable()->assertJsonValidationErrors('settings.provider.max_plans');
    $this->withToken($token)->putJson('/api/v1/admin/settings', ['settings' => ['nope' => ['key' => 1]]])
        ->assertUnprocessable()->assertJsonValidationErrors('settings.nope.key');
});

it('FR-AD7 lets only a super admin change secrets, and leaves an unchanged secret alone', function () {
    $this->withToken(adminToken())->putJson('/api/v1/admin/settings', ['settings' => ['stripe' => ['secret_key' => 'sk_test_x']]])
        ->assertForbidden();

    $super = adminToken('super_admin');
    $this->withToken($super)->putJson('/api/v1/admin/settings', ['settings' => ['stripe' => ['secret_key' => 'sk_test_x']]])->assertOk();
    expect(app(SettingsService::class)->get('stripe.secret_key'))->toBe('sk_test_x');

    app('auth')->forgetGuards();
    $this->withToken($super)->putJson('/api/v1/admin/settings', ['settings' => ['stripe' => ['secret_key' => null], 'provider' => ['max_plans' => 7]]])->assertOk();
    expect(app(SettingsService::class)->get('stripe.secret_key'))->toBe('sk_test_x');
});
