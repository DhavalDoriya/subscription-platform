<?php

use App\Models\Activity;
use App\Models\Provider;
use App\Models\User;
use App\Services\Billing\Reconciler;
use App\Services\Stripe\StripeGateway;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Support\FakeStripeGateway;

it('FR-PM6 repairs a subscription whose status drifted from Stripe', function () {
    [$provider, $plan, $price] = liveStorefront();
    $subscription = subscribe(User::factory()->create(), $provider, $plan, $price, ['status' => 'active']);
    fakeStripe()->subscriptions[$subscription->stripe_subscription_id]['status'] = 'past_due'; // a webhook was missed

    $report = app(Reconciler::class)->run();

    expect($subscription->fresh()->status->value)->toBe('past_due')
        ->and($report)->toMatchArray(['providers' => 1, 'checked' => 1, 'drifted' => 1, 'errors' => 0]);
    expect(Activity::where('description', 'billing.drift_repaired')->count())->toBe(1);
});

it('FR-PM6 leaves matching subscriptions alone', function () {
    [$provider, $plan, $price] = liveStorefront();
    $subscription = subscribe(User::factory()->create(), $provider, $plan, $price);

    $report = app(Reconciler::class)->run();

    expect($report)->toMatchArray(['checked' => 1, 'drifted' => 0])->and(Activity::where('description', 'billing.drift_repaired')->count())->toBe(0);
});

it('FR-PM6 ignores Stripe subscriptions we never created and providers without Stripe', function () {
    [$provider, $plan, $price] = liveStorefront();
    subscribe(User::factory()->create(), $provider, $plan, $price);
    fakeStripe()->subscriptions['sub_foreign'] = ['id' => 'sub_foreign', 'status' => 'active'] + fakeStripe()->subscriptions[array_key_first(fakeStripe()->subscriptions)];
    Provider::factory()->create(['slug' => 'nostripe', 'stripe_account_id' => null, 'stripe_status' => 'not_started']);

    $report = app(Reconciler::class)->run();

    expect($report)->toMatchArray(['providers' => 1, 'checked' => 1, 'drifted' => 0]);
    expect(fakeStripe()->callsTo('listSubscriptions'))->toHaveCount(1);
});

it('FR-PM6 one provider failing does not stop the others', function () {
    [$provider, $plan, $price] = liveStorefront();
    $subscription = subscribe(User::factory()->create(), $provider, $plan, $price);
    $broken = Provider::factory()->create(['slug' => 'broken', 'stripe_account_id' => 'acct_broken', 'stripe_status' => 'enabled']);

    $fake = new class extends FakeStripeGateway
    {
        public function listSubscriptions(string $accountId): array
        {
            if ($accountId === 'acct_broken') {
                throw new RuntimeException('account_invalid');
            }

            return parent::listSubscriptions($accountId);
        }
    };
    $fake->subscriptions = fakeStripe()->subscriptions;
    $fake->subscriptions[$subscription->stripe_subscription_id]['status'] = 'canceled';
    $this->app->instance(StripeGateway::class, $fake);

    $report = app(Reconciler::class)->run();

    expect($report)->toMatchArray(['providers' => 2, 'errors' => 1, 'drifted' => 1])->and($subscription->fresh()->status->value)->toBe('canceled');
});

it('FR-PM6 runs from the console and is scheduled every night', function () {
    $this->artisan('billing:reconcile')->assertSuccessful()->expectsOutputToContain('Checked');

    $scheduled = collect(app(Schedule::class)->events())->map->command->filter(fn ($c) => str_contains((string) $c, 'billing:reconcile'));
    expect($scheduled)->toHaveCount(1);
});
