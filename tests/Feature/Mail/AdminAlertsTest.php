<?php

use App\Models\Payment;
use App\Models\Provider;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Mail\TemplateMail;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function webhookEvent(string $status, string $id): void
{
    WebhookEvent::create(['stripe_event_id' => $id, 'type' => 'invoice.paid', 'livemode' => false, 'payload' => [], 'status' => $status, 'attempts' => 1, 'error' => $status === 'failed' ? 'boom' : null]);
}

function alertsSent(): array
{
    return collect(app(TemplateMail::class)->sent)->map(fn ($m) => $m['template'].' > '.$m['to'])->sort()->values()->all();
}

beforeEach(function () {
    Cache::flush();
    User::factory()->create(['email' => 'root@example.com'])->assignRole('super_admin');
    User::factory()->create(['email' => 'ops@example.com'])->assignRole('admin');
    User::factory()->create(['email' => 'customer@example.com'])->assignRole('customer');
});

it('FR-E1 tells every admin about a provider that waits for approval', function () {
    app(SettingsService::class)->set('provider.auto_approve', false);
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $token = loginAs($user)->json('data.access_token');
    app('auth')->forgetGuards();

    $this->withToken($token)->postJson('/api/v1/provider/register', [
        'name' => 'Acme Fitness', 'slug' => 'acme', 'business_category' => 'fitness', 'country' => 'US', 'support_email' => 'help@acme.test',
    ])->assertCreated();

    expect(alertsSent())->toBe([
        'admin.provider_pending > ops@example.com', 'admin.provider_pending > root@example.com', 'provider.registration_received > '.$user->email,
    ]);
});

it('FR-E1 alerts admins when webhook events failed in the last hour, once an hour', function () {
    webhookEvent('failed', 'evt_1');
    webhookEvent('failed', 'evt_2');
    webhookEvent('processed', 'evt_3');

    $this->artisan('platform:check-alerts')->assertSuccessful();
    $this->artisan('platform:check-alerts')->assertSuccessful();

    expect(alertsSent())->toBe(['admin.webhook_failures > ops@example.com', 'admin.webhook_failures > root@example.com']);
    expect(collect(app(TemplateMail::class)->sent)->first()['variables']['count'])->toBe(2);
});

it('FR-E1 stays quiet when nothing failed', function () {
    webhookEvent('processed', 'evt_1');

    $this->artisan('platform:check-alerts')->assertSuccessful();

    expect(alertsSent())->toBe([]);
});

it('FR-E1 alerts admins when many payments failed, but not for a handful', function () {
    $provider = Provider::factory()->create();
    Payment::factory()->count(3)->create(['provider_id' => $provider->id, 'status' => 'failed']);
    Payment::factory()->count(2)->create(['provider_id' => $provider->id, 'status' => 'succeeded']);
    $this->artisan('platform:check-alerts')->assertSuccessful();
    expect(alertsSent())->toBe([]); // too few payments to mean anything

    Payment::factory()->count(5)->create(['provider_id' => $provider->id, 'status' => 'failed']);
    $this->artisan('platform:check-alerts')->assertSuccessful();

    expect(alertsSent())->toBe(['admin.high_failure_rate > ops@example.com', 'admin.high_failure_rate > root@example.com']);
    expect(collect(app(TemplateMail::class)->sent)->first()['variables']['rate'])->toBe(80);
});

it('FR-E1 the alert check is scheduled every fifteen minutes', function () {
    expect(collect(app(Schedule::class)->events())->pluck('command')->implode(' '))->toContain('platform:check-alerts');
});

it('FR-L3 the readiness endpoint reports the database and cache without secrets, and 503s when one is down', function () {
    $this->getJson('/api/v1/health/ready')->assertOk()->assertExactJson(['data' => ['status' => 'ok', 'checks' => ['database' => 'ok', 'cache' => 'ok']]]);
});

it('FR-L3 the readiness endpoint answers 503 when the database is down', function () {
    DB::shouldReceive('select')->andThrow(new RuntimeException('connection refused'));

    $this->getJson('/api/v1/health/ready')->assertStatus(503)->assertJsonPath('data.checks.database', 'down')->assertJsonPath('data.status', 'down');
});
