<?php

use App\Enums\ProviderStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Provider;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Dunning\DunningService;
use App\Services\Providers\ProviderLifecycle;
use App\Services\Settings\SettingsService;
use App\Services\Stripe\StripeGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeStripeGateway;

/** @return array{Provider, array<string, Subscription>} */
function providerWithSubscriptions(): array
{
    [$provider, $plan, $price] = liveStorefront();
    $subs = [];
    foreach (['active', 'trialing', 'past_due', 'canceled', 'incomplete'] as $status) {
        $subs[$status] = subscribe(User::factory()->create(), $provider, $plan, $price, ['status' => $status, 'stripe_subscription_id' => "sub_{$status}"]);
    }

    return [$provider, $subs];
}

function transition(Provider $provider, ProviderStatus $to): void
{
    app(ProviderLifecycle::class)->transition($provider->fresh(), $to, null);
}

it('FR-P9 deactivating a provider pauses billing on its live subscriptions', function () {
    [$provider, $subs] = providerWithSubscriptions();

    transition($provider, ProviderStatus::Inactive);

    foreach (['active', 'trialing', 'past_due'] as $status) {
        $sub = $subs[$status]->fresh();
        expect($sub->status)->toBe(SubscriptionStatus::Paused)->and($sub->pause_reason)->toBe('provider');
    }
    expect($subs['canceled']->fresh()->status)->toBe(SubscriptionStatus::Canceled)
        ->and($subs['incomplete']->fresh()->status)->toBe(SubscriptionStatus::Incomplete);

    $calls = fakeStripe()->callsTo('updateSubscription');
    expect($calls)->toHaveCount(3)
        ->and($calls[0]['account'])->toBe($provider->stripe_account_id)
        ->and($calls[0]['params'])->toBe(['pause_collection' => ['behavior' => 'void']])
        ->and($calls[0]['idempotency_key'])->toStartWith('provider-pause-');
});

it('FR-P9 suspending pauses billing too', function () {
    [$provider, $subs] = providerWithSubscriptions();

    transition($provider, ProviderStatus::Suspended);

    expect($subs['active']->fresh()->status)->toBe(SubscriptionStatus::Paused);
});

it('FR-P9 reactivating resumes what the pause stopped, and only that', function () {
    [$provider, $subs] = providerWithSubscriptions();
    $subs['trialing']->forceFill(['status' => SubscriptionStatus::Paused, 'pause_reason' => 'dunning', 'paused_at' => now()])->save();
    fakeStripe()->subscriptions['sub_trialing']['paused'] = true;
    transition($provider, ProviderStatus::Inactive);
    fakeStripe()->calls = [];

    transition($provider, ProviderStatus::Active);

    expect($subs['active']->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($subs['active']->fresh()->pause_reason)->toBeNull()
        ->and($subs['active']->fresh()->paused_at)->toBeNull()
        ->and($subs['trialing']->fresh()->status)->toBe(SubscriptionStatus::Paused)
        ->and($subs['trialing']->fresh()->pause_reason)->toBe('dunning');

    $calls = fakeStripe()->callsTo('updateSubscription');
    expect(collect($calls)->pluck('subscription')->sort()->values()->all())->toBe(['sub_active', 'sub_past_due'])
        ->and($calls[0]['params'])->toBe(['pause_collection' => '']);
});

it('FR-P9 a Stripe failure on one subscription does not block the status change or the others', function () {
    [$provider, $subs] = providerWithSubscriptions();
    Log::spy();
    app()->instance(StripeGateway::class, new class extends FakeStripeGateway
    {
        public function updateSubscription(string $accountId, string $subscriptionId, array $params, string $idempotencyKey): array
        {
            if ($subscriptionId === 'sub_active') {
                throw new RuntimeException('Stripe is down');
            }

            return parent::updateSubscription($accountId, $subscriptionId, $params, $idempotencyKey);
        }
    });
    foreach ($subs as $sub) {
        app(StripeGateway::class)->subscriptions[$sub->stripe_subscription_id] = ['id' => $sub->stripe_subscription_id, 'status' => $sub->status->value, 'customer_id' => 'cus', 'price_id' => $sub->price->stripe_price_id, 'item_id' => 'si', 'current_period_start' => now()->timestamp, 'current_period_end' => now()->addMonth()->timestamp, 'trial_end' => null, 'cancel_at_period_end' => false, 'canceled_at' => null, 'ended_at' => null, 'paused' => false, 'latest_invoice_id' => null, 'client_secret' => null, 'client_secret_type' => null];
    }

    transition($provider, ProviderStatus::Inactive);

    expect($provider->fresh()->status)->toBe(ProviderStatus::Inactive)
        ->and($subs['active']->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($subs['trialing']->fresh()->status)->toBe(SubscriptionStatus::Paused);
    Log::shouldHaveReceived('error')->once();
});

it('FR-P9 only the deactivated provider is touched', function () {
    [$provider] = providerWithSubscriptions();
    [$other, $plan, $price] = liveStorefront(['slug' => 'other', 'stripe_account_id' => 'acct_other'], ['name' => 'Other plan']);
    $mine = subscribe(User::factory()->create(), $other, $plan, $price, ['stripe_subscription_id' => 'sub_other']);

    transition($provider, ProviderStatus::Inactive);

    expect($mine->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('FR-P9 grace that runs out while the provider is paused is not ended', function () {
    [$provider, $subs] = providerWithSubscriptions();
    $subs['active']->forceFill(['status' => SubscriptionStatus::Grace, 'grace_ends_at' => now()->subDay()])->save();
    $provider->forceFill(['status' => ProviderStatus::Inactive])->save();

    expect(app(DunningService::class)->expireGrace())->toBe(0)
        ->and($subs['active']->fresh()->status)->toBe(SubscriptionStatus::Grace);
});

it('FR-R5 a subscription paused by the dunning final action is marked as such', function () {
    app(SettingsService::class)->set('dunning.final_action', 'pause');
    $invoice = openInvoice();
    app(DunningService::class)->recordFailure($invoice, 'stolen_card', null, 'pi_x');

    Carbon::setTestNow(now()->addDays(8));
    app(DunningService::class)->expireGrace();
    Carbon::setTestNow();

    expect($invoice->subscription->fresh()->pause_reason)->toBe('dunning');
});
