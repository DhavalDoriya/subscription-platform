<?php

use App\Enums\ProviderStatus;
use App\Models\Activity;
use App\Models\Provider;
use App\Models\User;

it('FR-AD9 lets only a super admin manage admins', function () {
    $admin = User::factory()->create(['password' => 'Password123!'])->assignRole('admin');
    $this->withToken(loginAs($admin)->json('data.access_token'));

    $this->getJson('/api/v1/admin/admins')->assertForbidden();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/admin/admins', ['name' => 'N', 'email' => 'n@example.com', 'password' => 'Password123!'])->assertForbidden();
});

it('FR-AD9 lists, creates and removes admins, auditing each change', function () {
    $super = User::factory()->create(['password' => 'Password123!'])->assignRole('super_admin');
    User::factory()->create(['email' => 'old@example.com'])->assignRole('admin');
    User::factory()->create()->assignRole('customer');
    $this->withToken(loginAs($super)->json('data.access_token'));

    $this->getJson('/api/v1/admin/admins')->assertOk()->assertJsonPath('meta.total', 2); // the super admin and the admin

    $created = $this->postJson('/api/v1/admin/admins', ['name' => 'New Admin', 'email' => 'new@example.com', 'password' => 'Password123!'])
        ->assertCreated()->assertJsonPath('data.roles', ['admin'])->assertJsonMissingPath('data.password');
    $new = User::find($created->json('data.id'));
    expect($new->hasVerifiedEmail())->toBeTrue()->and(Activity::where('description', 'admin.created')->count())->toBe(1);

    $this->postJson('/api/v1/admin/admins', ['name' => 'Dup', 'email' => 'new@example.com', 'password' => 'Password123!'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');

    $this->deleteJson("/api/v1/admin/admins/{$new->id}")->assertNoContent();
    expect(User::find($new->id))->toBeNull()->and(Activity::where('description', 'admin.removed')->count())->toBe(1);
});

it('FR-AD9 will not remove yourself or a super admin, or a customer through this endpoint', function () {
    $super = User::factory()->create(['password' => 'Password123!'])->assignRole('super_admin');
    $otherSuper = User::factory()->create()->assignRole('super_admin');
    $customer = User::factory()->create()->assignRole('customer');
    $this->withToken(loginAs($super)->json('data.access_token'));

    $this->deleteJson("/api/v1/admin/admins/{$super->id}")->assertStatus(409)->assertJsonPath('code', 'cannot_remove_self');
    $this->deleteJson("/api/v1/admin/admins/{$otherSuper->id}")->assertStatus(409)->assertJsonPath('code', 'cannot_remove_super_admin');
    $this->deleteJson("/api/v1/admin/admins/{$customer->id}")->assertNotFound();
});

it('FR-AD1 summarises providers by status, customers and recent signups', function () {
    Provider::factory()->count(2)->create(['status' => ProviderStatus::Active]);
    Provider::factory()->create(['status' => ProviderStatus::Pending, 'name' => 'Newest']);
    User::factory()->count(3)->create()->each->assignRole('customer');
    $admin = User::factory()->create(['password' => 'Password123!'])->assignRole('admin');
    $this->withToken(loginAs($admin)->json('data.access_token'));

    $this->getJson('/api/v1/admin/dashboard')->assertOk()
        ->assertJsonPath('data.providers.total', 3)
        ->assertJsonPath('data.providers.by_status.active', 2)
        ->assertJsonPath('data.providers.by_status.pending', 1)
        ->assertJsonPath('data.providers.by_status.suspended', 0)
        ->assertJsonPath('data.customers.total', 3)
        ->assertJsonCount(3, 'data.recent_providers')
        ->assertJsonStructure(['data' => ['recent_customers' => [['id', 'name', 'email', 'created_at']]]]);
});
