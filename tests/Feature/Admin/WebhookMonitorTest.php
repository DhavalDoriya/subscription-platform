<?php

use App\Enums\ProviderStatus;
use App\Models\Activity;
use App\Models\Plan;
use App\Models\Provider;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Stripe\Webhooks\StripeEventHandler;
use Illuminate\Support\Str;

function adminLogin(string $role = 'admin'): User
{
    $admin = User::factory()->create(['password' => 'Password123!'])->assignRole($role);
    test()->withToken(loginAs($admin)->json('data.access_token'));
    app('auth')->forgetGuards();

    return $admin;
}

function storedEvent(array $overrides = []): WebhookEvent
{
    $id = 'evt_'.Str::random(10);
    $attributes = array_merge([
        'type' => 'account.updated',
        'account_id' => 'acct_1',
        'livemode' => false,
        'payload' => stripeEvent('account.updated', ['id' => 'acct_1']),
        'status' => 'processed',
        'attempts' => 1,
        'processed_at' => now(),
    ], $overrides);
    // As in real life, the id on the row is the id inside the payload.
    $attributes['payload']['id'] = $id;

    return WebhookEvent::create($attributes + ['stripe_event_id' => $id]);
}

it('FR-AD11 keeps the webhook monitor behind admin roles', function () {
    $this->getJson('/api/v1/admin/webhook-events')->assertUnauthorized();
    $customer = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $this->withToken(loginAs($customer)->json('data.access_token'))->getJson('/api/v1/admin/webhook-events')->assertForbidden();
});

it('FR-AD11 lists events in the pinned envelope with type and status filters, without the payload', function () {
    storedEvent(['type' => 'account.updated', 'status' => 'processed']);
    storedEvent(['type' => 'invoice.paid', 'status' => 'failed', 'error' => 'boom']);
    storedEvent(['type' => 'invoice.paid', 'status' => 'processed']);
    adminLogin();

    $this->getJson('/api/v1/admin/webhook-events')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonMissingPath('data.0.payload');
    $this->getJson('/api/v1/admin/webhook-events?status=failed')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.error', 'boom');
    $this->getJson('/api/v1/admin/webhook-events?search=invoice')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/admin/webhook-events?status=bogus')->assertUnprocessable()->assertJsonValidationErrors('status');
});

it('FR-AD11 shows one event with its payload', function () {
    $event = storedEvent();
    adminLogin();

    $this->getJson("/api/v1/admin/webhook-events/{$event->id}")->assertOk()
        ->assertJsonPath('data.payload.type', 'account.updated')->assertJsonPath('data.stripe_event_id', $event->stripe_event_id);
});

it('FR-AD11 replays a failed event through its handler and marks it processed', function () {
    $provider = Provider::factory()->create(['stripe_account_id' => 'acct_r', 'stripe_status' => 'pending', 'status' => ProviderStatus::Suspended]);
    $event = storedEvent([
        'status' => 'failed', 'error' => 'database hiccup', 'attempts' => 1,
        'payload' => stripeEvent('account.updated', ['id' => 'acct_r', 'details_submitted' => true, 'charges_enabled' => true, 'payouts_enabled' => true]),
    ]);
    $admin = adminLogin();

    $this->postJson("/api/v1/admin/webhook-events/{$event->id}/replay")->assertOk()->assertJsonPath('data.status', 'processed');

    expect($provider->fresh()->stripe_status)->toBe('enabled')
        ->and($event->fresh()->attempts)->toBe(2)->and($event->fresh()->error)->toBeNull()
        ->and(Activity::where('description', 'webhook.replayed')->first()->causer_id)->toBe($admin->id);
});

it('FR-AD11 replays a stuck handler failure again and reports it instead of crashing', function () {
    config(['stripe_webhooks.handlers' => ['account.updated' => FailingHandlerForReplay::class]]);
    $event = storedEvent(['status' => 'failed']);
    adminLogin();

    $this->postJson("/api/v1/admin/webhook-events/{$event->id}/replay")->assertStatus(422)->assertJsonPath('code', 'replay_failed');
    expect($event->fresh()->status)->toBe('failed')->and($event->fresh()->error)->toContain('still broken');
});

it('FR-AD11 refuses to replay an event that already succeeded', function () {
    $event = storedEvent(['status' => 'processed']);
    adminLogin();

    $this->postJson("/api/v1/admin/webhook-events/{$event->id}/replay")->assertStatus(409)->assertJsonPath('code', 'event_not_replayable');
});

class FailingHandlerForReplay implements StripeEventHandler
{
    public function handle(array $event): void
    {
        throw new RuntimeException('still broken');
    }
}

it('FR-AD5 lists every provider\'s plans for admins, with provider and status filters', function () {
    $a = Provider::factory()->create(['name' => 'Alpha']);
    $b = Provider::factory()->create(['name' => 'Beta']);
    Plan::factory()->create(['provider_id' => $a->id, 'name' => 'Gold', 'status' => 'active']);
    Plan::factory()->create(['provider_id' => $b->id, 'name' => 'Silver', 'status' => 'draft']);
    adminLogin();

    $this->getJson('/api/v1/admin/plans')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonStructure(['data' => [['id', 'name', 'status', 'provider' => ['id', 'name', 'slug']]]]);
    $this->getJson("/api/v1/admin/plans?provider_id={$a->id}")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Gold');
    $this->getJson('/api/v1/admin/plans?status=draft')->assertOk()->assertJsonPath('data.0.name', 'Silver');
    $this->getJson('/api/v1/admin/plans?search=gol')->assertOk()->assertJsonPath('meta.total', 1);
});

it('FR-AD5 lets an admin force-archive a plan, hiding its product on Stripe, and audits it', function () {
    $provider = Provider::factory()->create(['stripe_account_id' => 'acct_f', 'stripe_status' => 'enabled']);
    $plan = Plan::factory()->create(['provider_id' => $provider->id, 'status' => 'active', 'stripe_product_id' => 'prod_f']);
    $admin = adminLogin();

    $this->postJson("/api/v1/admin/plans/{$plan->id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');

    $update = fakeStripe()->callsTo('updateProduct')[0];
    expect($update['account'])->toBe('acct_f')->and($update['product'])->toBe('prod_f')->and($update['params'])->toBe(['active' => false])
        ->and(Activity::where('description', 'plan.force_archived')->first()->causer_id)->toBe($admin->id);
});

it('FR-AD5 keeps plan administration behind admin roles', function () {
    $plan = Plan::factory()->create();
    $customer = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $this->withToken(loginAs($customer)->json('data.access_token'));

    $this->getJson('/api/v1/admin/plans')->assertForbidden();
    $this->postJson("/api/v1/admin/plans/{$plan->id}/archive")->assertForbidden();
});
