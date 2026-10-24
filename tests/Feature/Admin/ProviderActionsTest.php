<?php

use App\Enums\ProviderStatus;
use App\Models\Activity;
use App\Models\Provider;
use App\Models\User;

function asAdmin(string $role = 'admin'): User
{
    $admin = User::factory()->create(['password' => 'Password123!'])->assignRole($role);
    $token = loginAs($admin)->json('data.access_token');
    app('auth')->forgetGuards();
    test()->withToken($token);

    return $admin;
}

it('FR-AD4 keeps provider administration behind admin roles', function () {
    $provider = Provider::factory()->create();

    $this->getJson('/api/v1/admin/providers')->assertUnauthorized();

    $customer = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $this->withToken(loginAs($customer)->json('data.access_token'))->getJson('/api/v1/admin/providers')->assertForbidden();
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/admin/providers/{$provider->id}/suspend")->assertForbidden();
});

it('FR-AD2 lists providers in the pinned envelope with search, status filter, sort and pagination', function () {
    Provider::factory()->create(['name' => 'Alpha Gym', 'slug' => 'alpha', 'status' => ProviderStatus::Active]);
    Provider::factory()->create(['name' => 'Beta Yoga', 'slug' => 'beta', 'status' => ProviderStatus::Pending]);
    Provider::factory()->create(['name' => 'Gamma Gym', 'slug' => 'gamma', 'status' => ProviderStatus::Active]);
    asAdmin();

    $this->getJson('/api/v1/admin/providers?per_page=2&sort=name&direction=desc')
        ->assertOk()
        ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
        ->assertJsonPath('data.0.name', 'Gamma Gym')
        ->assertJsonPath('data.1.name', 'Beta Yoga');

    $this->getJson('/api/v1/admin/providers?search=gym&sort=name')->assertOk()
        ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.slug', 'alpha');
    $this->getJson('/api/v1/admin/providers?status=pending')->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'beta');
    $this->getJson('/api/v1/admin/providers?status=bogus')->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->getJson('/api/v1/admin/providers?sort=password')->assertOk(); // unknown sort column falls back, never errors
});

it('FR-AD2 shows one provider with its owner and customer count', function () {
    $provider = Provider::factory()->create();
    $owner = User::factory()->create();
    $provider->members()->create(['user_id' => $owner->id, 'role' => 'owner']);
    asAdmin();

    $this->getJson("/api/v1/admin/providers/{$provider->id}")->assertOk()
        ->assertJsonPath('data.id', $provider->id)
        ->assertJsonPath('data.owner.email', $owner->email)
        ->assertJsonPath('data.customers_count', 0);

    $this->getJson('/api/v1/admin/providers/'.fake()->uuid())->assertNotFound();
});

it('FR-AD4 approves, activates, deactivates and suspends along the lifecycle, auditing each step', function () {
    $provider = Provider::factory()->create(['status' => ProviderStatus::Pending, 'approved_at' => null]);
    $admin = asAdmin();

    $this->postJson("/api/v1/admin/providers/{$provider->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    expect($provider->fresh()->approved_at)->not->toBeNull();

    $this->postJson("/api/v1/admin/providers/{$provider->id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
    $this->postJson("/api/v1/admin/providers/{$provider->id}/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
    $this->postJson("/api/v1/admin/providers/{$provider->id}/activate")->assertOk();
    $this->postJson("/api/v1/admin/providers/{$provider->id}/suspend")->assertOk()->assertJsonPath('data.status', 'suspended');

    $log = Activity::where('subject_id', $provider->id)->orderBy('created_at')->get();
    expect($log->pluck('description')->all())->toBe(['provider.approved', 'provider.activated', 'provider.deactivated', 'provider.activated', 'provider.suspended'])
        ->and($log->first()->causer_id)->toBe($admin->id)
        ->and($log->first()->properties['from'])->toBe('pending')
        ->and($log->first()->properties['to'])->toBe('approved');
});

it('FR-AD4 rejects with a reason, which the owner can later read', function () {
    $provider = Provider::factory()->create(['status' => ProviderStatus::Pending]);
    asAdmin();

    $this->postJson("/api/v1/admin/providers/{$provider->id}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/admin/providers/{$provider->id}/reject", ['reason' => 'Incomplete business details'])
        ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Incomplete business details');
});

it('FR-P2 refuses transitions the lifecycle does not allow', function () {
    $provider = Provider::factory()->create(['status' => ProviderStatus::Pending]);
    asAdmin();

    $this->postJson("/api/v1/admin/providers/{$provider->id}/activate")
        ->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
    $this->postJson("/api/v1/admin/providers/{$provider->id}/suspend")->assertStatus(409);

    expect($provider->fresh()->status)->toBe(ProviderStatus::Pending)
        ->and(Activity::count())->toBe(0);
});

it('FR-P3 lets an admin change a slug after approval, with the same rules', function () {
    $provider = Provider::factory()->create(['slug' => 'old-name']);
    Provider::factory()->create(['slug' => 'taken-one']);
    asAdmin();

    $this->patchJson("/api/v1/admin/providers/{$provider->id}", ['slug' => 'new-name'])->assertOk()->assertJsonPath('data.slug', 'new-name');
    $this->patchJson("/api/v1/admin/providers/{$provider->id}", ['slug' => 'taken-one'])->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->patchJson("/api/v1/admin/providers/{$provider->id}", ['slug' => 'www'])->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->patchJson("/api/v1/admin/providers/{$provider->id}", ['slug' => 'new-name'])->assertOk(); // unchanged is fine

    expect(Activity::where('description', 'provider.updated')->count())->toBe(1);
});
