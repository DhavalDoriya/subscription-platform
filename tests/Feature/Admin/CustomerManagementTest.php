<?php

use App\Models\Activity;
use App\Models\Provider;
use App\Models\ProviderCustomer;
use App\Models\RefreshToken;
use App\Models\User;

function adminSession(string $role = 'admin'): User
{
    $admin = User::factory()->create(['password' => 'Password123!'])->assignRole($role);
    test()->withToken(loginAs($admin)->json('data.access_token'));
    app('auth')->forgetGuards();

    return $admin;
}

function customer(array $attributes = []): User
{
    return User::factory()->create(array_merge(['password' => 'Password123!'], $attributes))->assignRole('customer');
}

it('FR-AD3 lists only customers with search, blocked filter and the pinned envelope', function () {
    customer(['name' => 'Ann Lee', 'email' => 'ann@example.com']);
    customer(['name' => 'Bob Ray', 'email' => 'bob@example.com', 'blocked_at' => now()]);
    User::factory()->create()->assignRole('admin'); // not a customer
    adminSession();

    $this->getJson('/api/v1/admin/customers')->assertOk()->assertJsonPath('meta.total', 2)
        ->assertJsonMissingPath('data.0.password');
    $this->getJson('/api/v1/admin/customers?search=ann')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'ann@example.com');
    $this->getJson('/api/v1/admin/customers?blocked=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.is_blocked', true);
    $this->getJson('/api/v1/admin/customers?blocked=0')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Ann Lee');
});

it('FR-AD3 shows a customer with the providers they subscribed to', function () {
    $user = customer();
    $acme = Provider::factory()->create(['name' => 'Acme']);
    ProviderCustomer::factory()->create(['provider_id' => $acme->id, 'user_id' => $user->id]);
    ProviderCustomer::factory()->create(); // someone else's link
    adminSession();

    $this->getJson("/api/v1/admin/customers/{$user->id}")->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonCount(1, 'data.providers')
        ->assertJsonPath('data.providers.0.name', 'Acme');
});

it('FR-AD3 blocks and unblocks, revoking sessions and auditing', function () {
    $user = customer();
    loginAs($user);
    app('auth')->forgetGuards();
    $admin = adminSession();

    $this->postJson("/api/v1/admin/customers/{$user->id}/block")->assertOk()->assertJsonPath('data.is_blocked', true);

    expect($user->fresh()->blocked_at)->not->toBeNull()
        ->and(RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(0)
        ->and(Activity::where('description', 'customer.blocked')->first()->causer_id)->toBe($admin->id);

    $this->postJson("/api/v1/admin/customers/{$user->id}/unblock")->assertOk()->assertJsonPath('data.is_blocked', false);
    expect($user->fresh()->blocked_at)->toBeNull()
        ->and(Activity::where('description', 'customer.unblocked')->count())->toBe(1);
});

it('FR-AD3 refuses to block anyone who is not a customer', function () {
    $other = User::factory()->create()->assignRole('admin');
    adminSession();

    $this->postJson("/api/v1/admin/customers/{$other->id}/block")->assertNotFound();
});

it('FR-AD3 stops a blocked user from signing in, refreshing, or using a token they already hold', function () {
    $user = customer();
    $login = loginAs($user);
    $token = $login->json('data.access_token');
    $refresh = refreshCookie($login);
    app('auth')->forgetGuards();
    $user->forceFill(['blocked_at' => now()])->save();

    loginAs($user)->assertForbidden()->assertJsonPath('code', 'account_blocked');
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('code', 'account_blocked');
    app('auth')->forgetGuards();
    $this->withoutToken()->withCredentials()->withUnencryptedCookie('refresh_token', $refresh)->postJson('/api/v1/auth/refresh')->assertUnauthorized();
});

it('FR-AD3 keeps customer management behind admin roles', function () {
    $user = customer();
    $this->withToken(loginAs(customer(['email' => 'x@example.com']))->json('data.access_token'));

    $this->getJson('/api/v1/admin/customers')->assertForbidden();
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/admin/customers/{$user->id}/block")->assertForbidden();
});
