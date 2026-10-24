<?php

use App\Models\Activity;
use App\Models\Provider;
use App\Models\User;

it('FR-L1 records provider registration with the registering user as causer', function () {
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $token = loginAs($user)->json('data.access_token');
    app('auth')->forgetGuards();

    $this->withToken($token)->postJson('/api/v1/provider/register', [
        'name' => 'Acme', 'slug' => 'acme', 'business_category' => 'other', 'country' => 'US', 'support_email' => 'a@b.test',
    ])->assertCreated();

    $entry = Activity::where('description', 'provider.registered')->firstOrFail();
    expect($entry->causer_id)->toBe($user->id)
        ->and($entry->subject_id)->toBe(Provider::first()->id);
});

it('FR-L1 records settings changes by key and never stores secret values', function () {
    $super = User::factory()->create(['password' => 'Password123!'])->assignRole('super_admin');
    $token = loginAs($super)->json('data.access_token');
    app('auth')->forgetGuards();

    $this->withToken($token)->putJson('/api/v1/admin/settings', ['settings' => [
        'provider' => ['max_plans' => 3], 'stripe' => ['secret_key' => 'sk_test_topsecret'],
    ]])->assertOk();

    $entry = Activity::where('description', 'settings.updated')->firstOrFail();
    expect($entry->causer_id)->toBe($super->id)
        ->and($entry->properties['changes']['provider.max_plans'])->toBe(3)
        ->and($entry->properties['changes']['stripe.secret_key'])->toBe('[secret]')
        ->and(json_encode($entry->properties))->not->toContain('sk_test_topsecret');
});

it('FR-L1 keeps the audit log append-only', function () {
    $entry = activity()->log('provider.registered');

    expect(fn () => $entry->update(['description' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});

it('FR-AD10 lets admins browse the audit log with filters and the pinned envelope', function () {
    $provider = Provider::factory()->create();
    $other = Provider::factory()->create();
    activity()->performedOn($provider)->log('provider.approved');
    activity()->performedOn($provider)->log('provider.suspended');
    activity()->performedOn($other)->log('provider.approved');

    $admin = User::factory()->create(['password' => 'Password123!'])->assignRole('admin');
    $this->withToken(loginAs($admin)->json('data.access_token'));

    $this->getJson('/api/v1/admin/audit-logs?per_page=2')->assertOk()
        ->assertJsonPath('meta.total', 3)->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'description', 'causer', 'subject_type', 'subject_id', 'properties', 'created_at']]]);
    $this->getJson("/api/v1/admin/audit-logs?subject_id={$provider->id}")->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/admin/audit-logs?event=provider.suspended')->assertOk()->assertJsonPath('meta.total', 1);
});

it('FR-AD10 keeps the audit log away from non-admins', function () {
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $this->withToken(loginAs($user)->json('data.access_token'))->getJson('/api/v1/admin/audit-logs')->assertForbidden();
});
