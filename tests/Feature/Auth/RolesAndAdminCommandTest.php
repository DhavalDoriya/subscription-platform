<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

it('FR-A4 seeds the four roles', function () {
    expect(Role::pluck('name')->sort()->values()->all())->toBe(['admin', 'customer', 'provider_owner', 'super_admin']);
});

it('FR-A4 blocks users without the role and allows those with it', function () {
    Route::get('api/v1/_test/admin-only', fn () => response()->json(['data' => 'ok']))->middleware(['auth:api', 'role:admin,api']);

    $customer = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $admin = User::factory()->create(['password' => 'Password123!'])->assignRole('admin');

    $this->getJson('/api/v1/_test/admin-only')->assertUnauthorized();

    $token = loginAs($customer)->json('data.access_token');
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/_test/admin-only')->assertForbidden();

    $token = loginAs($admin)->json('data.access_token');
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/_test/admin-only')->assertOk();
});

it('creates the first admin with app:create-admin', function () {
    $this->artisan('app:create-admin', ['email' => 'root@example.com', '--name' => 'Root', '--password' => 'Password123!'])->assertSuccessful();

    $admin = User::where('email', 'root@example.com')->first();
    expect($admin->hasRole('admin'))->toBeTrue()
        ->and($admin->hasVerifiedEmail())->toBeTrue();
    loginAs($admin)->assertOk();
});

it('creates a super admin with --super', function () {
    $this->artisan('app:create-admin', ['email' => 'boss@example.com', '--name' => 'Boss', '--password' => 'Password123!', '--super' => true])->assertSuccessful();

    expect(User::where('email', 'boss@example.com')->first()->hasRole('super_admin'))->toBeTrue();
});

it('refuses to create an admin for an existing email', function () {
    User::factory()->create(['email' => 'root@example.com']);

    $this->artisan('app:create-admin', ['email' => 'root@example.com', '--name' => 'Root', '--password' => 'Password123!'])->assertFailed();
});

it('creates no half-made admin when the roles have not been seeded', function () {
    Role::query()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->artisan('app:create-admin', ['email' => 'root@example.com', '--name' => 'Root', '--password' => 'Password123!'])
        ->expectsOutputToContain('RoleSeeder')
        ->assertFailed();

    expect(User::withTrashed()->where('email', 'root@example.com')->exists())->toBeFalse();
});
