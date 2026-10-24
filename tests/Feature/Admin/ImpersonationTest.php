<?php

use App\Models\Activity;
use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

function actingAdmin(string $role = 'admin'): User
{
    $admin = User::factory()->create(['password' => 'Password123!'])->assignRole($role);
    test()->withToken(loginAs($admin)->json('data.access_token'));
    app('auth')->forgetGuards();

    return $admin;
}

it('FR-A10 gives an admin a short-lived token for a customer, auditing who and whom', function () {
    $target = User::factory()->create(['name' => 'Target'])->assignRole('customer');
    $admin = actingAdmin();

    $response = $this->postJson("/api/v1/admin/users/{$target->id}/impersonate")->assertOk()
        ->assertJsonPath('data.user.id', $target->id)
        ->assertJsonPath('data.impersonator.id', $admin->id)
        ->assertJsonPath('data.token_type', 'bearer');

    expect($response->getCookie('refresh_token', false))->toBeNull(); // no refresh: the session ends with the token

    $payload = JWTAuth::setToken($response->json('data.access_token'))->getPayload();
    expect($payload->get('sub'))->toBe($target->id)->and($payload->get('imp'))->toBe($admin->id);

    $entry = Activity::where('description', 'impersonation.started')->firstOrFail();
    expect($entry->causer_id)->toBe($admin->id)->and($entry->subject_id)->toBe($target->id);
});

it('FR-A10 acts as the target: /me returns them, and they cannot be impersonated onward', function () {
    $target = User::factory()->create()->assignRole('customer');
    actingAdmin();
    $token = $this->postJson("/api/v1/admin/users/{$target->id}/impersonate")->json('data.access_token');
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $target->id);
    $this->postJson("/api/v1/admin/users/{$target->id}/impersonate")->assertForbidden(); // customer role cannot reach admin routes
});

it('FR-A10 never allows impersonating admins or super admins, or yourself', function () {
    $otherAdmin = User::factory()->create()->assignRole('admin');
    $super = User::factory()->create()->assignRole('super_admin');
    $admin = actingAdmin();

    $this->postJson("/api/v1/admin/users/{$otherAdmin->id}/impersonate")->assertStatus(409)->assertJsonPath('code', 'cannot_impersonate');
    $this->postJson("/api/v1/admin/users/{$super->id}/impersonate")->assertStatus(409);
    $this->postJson("/api/v1/admin/users/{$admin->id}/impersonate")->assertStatus(409);
});

it('FR-A10 blocks credential changes while impersonating', function () {
    $target = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    actingAdmin();
    $token = $this->postJson("/api/v1/admin/users/{$target->id}/impersonate")->json('data.access_token');
    app('auth')->forgetGuards();

    $this->withToken($token)->putJson('/api/v1/auth/password', [
        'current_password' => 'Password123!', 'password' => 'NewPassword456!', 'password_confirmation' => 'NewPassword456!',
    ])->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($token)->patchJson('/api/v1/auth/profile', ['email' => 'hijack@example.com', 'current_password' => 'Password123!'])->assertForbidden();
});

it('FR-A10 records when an impersonation ends', function () {
    $target = User::factory()->create()->assignRole('customer');
    $admin = actingAdmin();
    $token = $this->postJson("/api/v1/admin/users/{$target->id}/impersonate")->json('data.access_token');
    app('auth')->forgetGuards();

    $this->withToken($token)->postJson('/api/v1/auth/impersonation/end')->assertNoContent();

    $entry = Activity::where('description', 'impersonation.ended')->firstOrFail();
    expect($entry->causer_id)->toBe($admin->id)->and($entry->subject_id)->toBe($target->id);
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized(); // token is dead
});

it('FR-A10 refuses to end an impersonation that is not one', function () {
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $this->withToken(loginAs($user)->json('data.access_token'))->postJson('/api/v1/auth/impersonation/end')->assertStatus(409);
});
