<?php

use App\Enums\ProviderStatus;
use App\Models\Activity;
use App\Models\Provider;
use App\Models\WebhookEvent;
use App\Services\Settings\SettingsService;
use App\Services\Stripe\Webhooks\StripeEventHandler;
use Illuminate\Support\Str;

beforeEach(function () {
    app(SettingsService::class)->set('stripe.webhook_secret', 'whsec_test');
    app(SettingsService::class)->set('stripe.connect_webhook_secret', 'whsec_connect');
});

function countingHandler(string $type): object
{
    $handler = new class implements StripeEventHandler
    {
        public int $handled = 0;

        public ?Throwable $fail = null;

        public function handle(array $event): void
        {
            if ($this->fail) {
                throw $this->fail;
            }
            $this->handled++;
        }
    };
    config(['stripe_webhooks.handlers' => [$type => $handler::class]]);
    app()->instance($handler::class, $handler);

    return $handler;
}

it('FR-PM3 rejects a webhook with a missing or wrong signature', function () {
    $event = stripeEvent('customer.created', ['id' => 'cus_1']);

    stripeWebhook('/api/v1/webhooks/stripe', $event, signature: 't=1,v1=deadbeef')->assertStatus(400)->assertJsonPath('code', 'invalid_signature');
    stripeWebhook('/api/v1/webhooks/stripe', $event, secret: 'whsec_other')->assertStatus(400);
    $this->postJson('/api/v1/webhooks/stripe', $event)->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0);
});

it('FR-PM3 rejects an event signed long ago (replay protection)', function () {
    $payload = json_encode(stripeEvent('customer.created', ['id' => 'cus_1']));
    $old = time() - 3600;
    $signature = 't='.$old.',v1='.hash_hmac('sha256', $old.'.'.$payload, 'whsec_test');

    stripeWebhook('/api/v1/webhooks/stripe', stripeEvent('customer.created', ['id' => 'cus_1']), signature: $signature)->assertStatus(400);
});

it('FR-PM3 checks platform and connect events against their own secrets', function () {
    $event = stripeEvent('customer.created', ['id' => 'cus_1']);

    stripeWebhook('/api/v1/webhooks/stripe', $event, secret: 'whsec_connect')->assertStatus(400);
    stripeWebhook('/api/v1/webhooks/stripe/connect', $event, secret: 'whsec_test')->assertStatus(400);
    stripeWebhook('/api/v1/webhooks/stripe/connect', $event, secret: 'whsec_connect')->assertOk();
});

it('FR-PM3 answers 503 while no webhook secret is configured', function () {
    app(SettingsService::class)->set('stripe.webhook_secret', null);

    stripeWebhook('/api/v1/webhooks/stripe', stripeEvent('customer.created', ['id' => 'c']))->assertStatus(503)->assertJsonPath('code', 'stripe_not_configured');
});

it('FR-PM4 stores the event and runs its handler once', function () {
    $handler = countingHandler('invoice.paid');
    $event = stripeEvent('invoice.paid', ['id' => 'in_1'], id: 'evt_one', account: 'acct_9');

    stripeWebhook('/api/v1/webhooks/stripe/connect', $event, 'whsec_connect')->assertOk()->assertJsonPath('data.received', true);

    $row = WebhookEvent::where('stripe_event_id', 'evt_one')->firstOrFail();
    expect($handler->handled)->toBe(1)
        ->and($row->type)->toBe('invoice.paid')
        ->and($row->account_id)->toBe('acct_9')
        ->and($row->status)->toBe('processed')
        ->and($row->processed_at)->not->toBeNull()
        ->and($row->payload['data']['object']['id'])->toBe('in_1');
});

it('FR-PM4 processes a redelivered event only once', function () {
    $handler = countingHandler('invoice.paid');
    $event = stripeEvent('invoice.paid', ['id' => 'in_1'], id: 'evt_dupe');

    stripeWebhook('/api/v1/webhooks/stripe', $event)->assertOk();
    stripeWebhook('/api/v1/webhooks/stripe', $event)->assertOk()->assertJsonPath('data.duplicate', true);
    stripeWebhook('/api/v1/webhooks/stripe', $event)->assertOk();

    expect($handler->handled)->toBe(1)->and(WebhookEvent::where('stripe_event_id', 'evt_dupe')->count())->toBe(1);
});

it('FR-PM4 stores events nobody handles as ignored and still answers 200', function () {
    config(['stripe_webhooks.handlers' => []]);

    stripeWebhook('/api/v1/webhooks/stripe', stripeEvent('radar.early_fraud_warning.created', ['id' => 'x'], id: 'evt_unknown'))->assertOk();

    expect(WebhookEvent::where('stripe_event_id', 'evt_unknown')->first()->status)->toBe('ignored');
});

it('FR-PM4 marks a failing handler failed, answers 500 so Stripe retries, and succeeds on redelivery', function () {
    $handler = countingHandler('invoice.paid');
    $handler->fail = new RuntimeException('database hiccup');
    $event = stripeEvent('invoice.paid', ['id' => 'in_1'], id: 'evt_flaky');

    stripeWebhook('/api/v1/webhooks/stripe', $event)->assertStatus(500);
    $row = WebhookEvent::where('stripe_event_id', 'evt_flaky')->first();
    expect($row->status)->toBe('failed')->and($row->error)->toContain('database hiccup')->and($row->attempts)->toBe(1);

    $handler->fail = null;
    stripeWebhook('/api/v1/webhooks/stripe', $event)->assertOk();
    expect($row->fresh()->status)->toBe('processed')->and($row->fresh()->attempts)->toBe(2)->and($handler->handled)->toBe(1);
});

it('FR-PM4 is a uuid, soft-deleted table like every other business table', function () {
    countingHandler('invoice.paid');
    stripeWebhook('/api/v1/webhooks/stripe', stripeEvent('invoice.paid', ['id' => 'in_1']))->assertOk();
    $row = WebhookEvent::first();

    expect(Str::isUuid($row->id))->toBeTrue();
    $row->delete();
    expect(WebhookEvent::withTrashed()->count())->toBe(1);
});

it('FR-P4 syncs onboarding progress from account.updated and activates the provider once Stripe is enabled', function () {
    $provider = Provider::factory()->create(['status' => ProviderStatus::Approved, 'stripe_account_id' => 'acct_77', 'stripe_status' => 'pending']);
    $account = ['id' => 'acct_77', 'object' => 'account', 'details_submitted' => true, 'charges_enabled' => true, 'payouts_enabled' => true, 'requirements' => ['disabled_reason' => null, 'currently_due' => []]];

    stripeWebhook('/api/v1/webhooks/stripe/connect', stripeEvent('account.updated', $account, account: 'acct_77'), 'whsec_connect')->assertOk();

    expect($provider->fresh()->stripe_status)->toBe('enabled')->and($provider->fresh()->status)->toBe(ProviderStatus::Active);
});

it('FR-P4 records restricted accounts without activating, and never moves a suspended provider', function () {
    $pending = Provider::factory()->create(['status' => ProviderStatus::Approved, 'stripe_account_id' => 'acct_a', 'stripe_status' => 'pending']);
    $suspended = Provider::factory()->create(['status' => ProviderStatus::Suspended, 'stripe_account_id' => 'acct_b', 'stripe_status' => 'pending']);

    stripeWebhook('/api/v1/webhooks/stripe/connect', stripeEvent('account.updated', ['id' => 'acct_a', 'details_submitted' => true, 'charges_enabled' => false, 'payouts_enabled' => false, 'requirements' => ['disabled_reason' => 'requirements.past_due']], account: 'acct_a'), 'whsec_connect')->assertOk();
    stripeWebhook('/api/v1/webhooks/stripe/connect', stripeEvent('account.updated', ['id' => 'acct_b', 'details_submitted' => true, 'charges_enabled' => true, 'payouts_enabled' => true], account: 'acct_b'), 'whsec_connect')->assertOk();

    expect($pending->fresh()->stripe_status)->toBe('restricted')->and($pending->fresh()->status)->toBe(ProviderStatus::Approved)
        ->and($suspended->fresh()->stripe_status)->toBe('enabled')->and($suspended->fresh()->status)->toBe(ProviderStatus::Suspended);
});

it('FR-P4 ignores account.updated for an account we do not know', function () {
    stripeWebhook('/api/v1/webhooks/stripe/connect', stripeEvent('account.updated', ['id' => 'acct_unknown', 'details_submitted' => true], id: 'evt_u', account: 'acct_unknown'), 'whsec_connect')->assertOk();

    expect(WebhookEvent::where('stripe_event_id', 'evt_u')->first()->status)->toBe('processed');
});

it('FR-L1 audits a Stripe status change with no human causer', function () {
    Provider::factory()->create(['status' => ProviderStatus::Approved, 'stripe_account_id' => 'acct_c', 'stripe_status' => 'pending']);

    stripeWebhook('/api/v1/webhooks/stripe/connect', stripeEvent('account.updated', ['id' => 'acct_c', 'details_submitted' => true, 'charges_enabled' => true, 'payouts_enabled' => true], account: 'acct_c'), 'whsec_connect')->assertOk();

    $entry = Activity::where('description', 'provider.stripe_status_changed')->firstOrFail();
    expect($entry->causer_id)->toBeNull()->and($entry->properties['from'])->toBe('pending')->and($entry->properties['to'])->toBe('enabled');
});
