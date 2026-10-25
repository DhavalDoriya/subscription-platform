<?php

use App\Enums\ProviderStatus as S;
use App\Models\Provider;
use App\Models\User;
use App\Services\Mail\TemplateMail;
use App\Services\Providers\ProviderLifecycle;
use App\Services\Settings\SettingsService;

it('FR-E1 mails the owner when the provider status changes', function (S $from, S $to, string $template) {
    [$owner, $provider] = providerOwnerSession(['status' => $from]);

    app(ProviderLifecycle::class)->transition($provider, $to, null, 'Incomplete information.');

    $sent = app(TemplateMail::class)->sent;
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['template'])->toBe($template)
        ->and($sent[0]['to'])->toBe($owner->email)
        ->and($sent[0]['provider_id'])->toBe($provider->id)
        ->and($sent[0]['variables']['provider.name'])->toBe($provider->name);
})->with([
    'approved' => [S::Pending, S::Approved, 'provider.approved'],
    'rejected' => [S::Pending, S::Rejected, 'provider.rejected'],
    'suspended' => [S::Active, S::Suspended, 'provider.suspended'],
    'reactivated' => [S::Suspended, S::Active, 'provider.reactivated'],
]);

it('FR-E1 sends no mail for transitions without a template', function () {
    [, $provider] = providerOwnerSession(['status' => S::Active]);

    app(ProviderLifecycle::class)->transition($provider, S::Inactive, null);

    expect(app(TemplateMail::class)->sent)->toBeEmpty();
});

it('FR-E1 acknowledges a registration that waits for approval, and welcomes an auto-approved one', function (bool $autoApprove, string $template) {
    app(SettingsService::class)->set('provider.auto_approve', $autoApprove);
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $token = loginAs($user)->json('data.access_token');
    app('auth')->forgetGuards();

    $this->withToken($token)->postJson('/api/v1/provider/register', [
        'name' => 'Acme Fitness', 'slug' => 'acme', 'business_category' => 'fitness', 'country' => 'US', 'support_email' => 'help@acme.test',
    ])->assertCreated();

    $sent = app(TemplateMail::class)->sent;
    expect($sent)->toHaveCount(1)->and($sent[0]['template'])->toBe($template)->and($sent[0]['to'])->toBe($user->email)
        ->and($sent[0]['variables']['provider.name'])->toBe('Acme Fitness');
})->with([
    'pending' => [false, 'provider.registration_received'],
    'auto-approved' => [true, 'provider.approved'],
]);

it('FR-E1 reminds a provider who has not finished Stripe onboarding after three days, once', function () {
    [$owner, $provider] = providerOwnerSession(['status' => S::Approved, 'stripe_status' => 'pending']);
    Provider::whereKey($provider->id)->update(['approved_at' => now()->subDays(4)]);

    $this->artisan('providers:remind-stripe-onboarding')->assertSuccessful();
    $this->artisan('providers:remind-stripe-onboarding')->assertSuccessful();

    $sent = app(TemplateMail::class)->sent;
    expect($sent)->toHaveCount(1)->and($sent[0]['template'])->toBe('provider.stripe_onboarding_reminder')->and($sent[0]['to'])->toBe($owner->email);
});

it('FR-E1 does not remind a provider that is new, already connected, or not live', function (array $state) {
    [, $provider] = providerOwnerSession($state + ['status' => S::Approved]);
    Provider::whereKey($provider->id)->update(['approved_at' => $state['stripe_status'] === 'pending' && ! isset($state['status']) ? now()->subHour() : now()->subDays(4)]);

    $this->artisan('providers:remind-stripe-onboarding')->assertSuccessful();

    expect(app(TemplateMail::class)->sent)->toBeEmpty();
})->with([
    'new' => [['stripe_status' => 'pending']],
    'enabled' => [['stripe_status' => 'enabled']],
    'suspended' => [['status' => S::Suspended, 'stripe_status' => 'pending']],
]);
