<?php

use App\Enums\ProviderStatus;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Provider;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Str;

function stripeReady(array $provider = []): array
{
    return providerOwnerSession(array_merge(['status' => ProviderStatus::Active, 'stripe_account_id' => 'acct_ready', 'stripe_status' => 'enabled'], $provider));
}

function planPayload(array $overrides = []): array
{
    return array_merge(['name' => 'Pro', 'description' => 'Everything', 'features' => ['Unlimited projects', 'Priority support'], 'trial_days' => 14], $overrides);
}

function pricePayload(array $overrides = []): array
{
    return array_merge(['amount' => 1900, 'currency' => 'usd', 'interval' => 'month', 'interval_count' => 1], $overrides);
}

function makePlan(Provider $provider, array $overrides = []): Plan
{
    return Plan::factory()->create(array_merge(['provider_id' => $provider->id], $overrides));
}

function makePrice(Plan $plan, array $overrides = []): Price
{
    return Price::factory()->create(array_merge(['provider_id' => $plan->provider_id, 'plan_id' => $plan->id], $overrides));
}

it('FR-PL1 creates a draft plan with features, trial and visibility', function () {
    [, $provider] = providerOwnerSession();

    $this->postJson('/api/v1/provider/plans', planPayload(['visibility' => 'unlisted', 'sort_order' => 3]))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Pro')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.visibility', 'unlisted')
        ->assertJsonPath('data.trial_days', 14)
        ->assertJsonPath('data.features', ['Unlimited projects', 'Priority support'])
        ->assertJsonPath('data.prices', []);

    $plan = Plan::first();
    expect(Str::isUuid($plan->id))->toBeTrue()->and($plan->provider_id)->toBe($provider->id);
});

it('FR-PL1 validates the plan', function (array $overrides, string $field) {
    providerOwnerSession();

    $this->postJson('/api/v1/provider/plans', planPayload($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'name required' => [['name' => ''], 'name'],
    'trial too long' => [['trial_days' => 731], 'trial_days'],
    'negative trial' => [['trial_days' => -1], 'trial_days'],
    'too many features' => [['features' => array_fill(0, 21, 'x')], 'features'],
    'feature not a string' => [['features' => [['a']]], 'features.0'],
    'unknown visibility' => [['visibility' => 'secret'], 'visibility'],
]);

it('FR-PL1 lists only this provider\'s plans and hides everyone else\'s', function () {
    [, $mine] = providerOwnerSession();
    $other = Provider::factory()->create();
    $own = makePlan($mine, ['name' => 'Mine']);
    $theirs = makePlan($other, ['name' => 'Theirs']);

    $this->getJson('/api/v1/provider/plans')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Mine');
    $this->getJson("/api/v1/provider/plans/{$own->id}")->assertOk();
    $this->getJson("/api/v1/provider/plans/{$theirs->id}")->assertNotFound();
    $this->patchJson("/api/v1/provider/plans/{$theirs->id}", ['name' => 'Hijack'])->assertNotFound();
    $this->postJson("/api/v1/provider/plans/{$theirs->id}/prices", pricePayload())->assertNotFound();
    expect($theirs->fresh()->name)->toBe('Theirs');
});

it('FR-PL1 keeps plan endpoints for provider members only', function () {
    $this->getJson('/api/v1/provider/plans')->assertUnauthorized();
    $stranger = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $this->withToken(loginAs($stranger)->json('data.access_token'));

    $this->getJson('/api/v1/provider/plans')->assertNotFound()->assertJsonPath('code', 'provider_not_found');
});

it('FR-PL1 updates a draft plan in place', function () {
    [, $provider] = providerOwnerSession();
    $plan = makePlan($provider);

    $this->patchJson("/api/v1/provider/plans/{$plan->id}", ['name' => 'Team', 'features' => ['One'], 'trial_days' => 0, 'sort_order' => 9])
        ->assertOk()->assertJsonPath('data.name', 'Team')->assertJsonPath('data.sort_order', 9);

    expect(fakeStripe()->calls)->toBe([]); // nothing on Stripe until it is published
});

it('FR-PL2 adds prices in integer minor units, in the provider\'s currency', function () {
    [, $provider] = providerOwnerSession();
    $plan = makePlan($provider);

    $this->postJson("/api/v1/provider/plans/{$plan->id}/prices", pricePayload(['amount' => 2900, 'interval' => 'year']))
        ->assertCreated()->assertJsonPath('data.amount', 2900)->assertJsonPath('data.interval', 'year')->assertJsonPath('data.currency', 'usd')->assertJsonPath('data.synced', false);

    $this->getJson("/api/v1/provider/plans/{$plan->id}")->assertJsonCount(1, 'data.prices');
});

it('FR-PL2 validates prices against the admin limits and the provider currency', function (array $overrides, string $field) {
    [, $provider] = providerOwnerSession();
    app(SettingsService::class)->set('provider.min_price', 500);
    app(SettingsService::class)->set('provider.max_price', 100000);
    app(SettingsService::class)->set('provider.allowed_intervals', ['month', 'year']);
    $plan = makePlan($provider);

    $this->postJson("/api/v1/provider/plans/{$plan->id}/prices", pricePayload($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'below the minimum' => [['amount' => 499], 'amount'],
    'above the maximum' => [['amount' => 100001], 'amount'],
    'fractional amount' => [['amount' => 19.5], 'amount'],
    'interval not allowed' => [['interval' => 'week'], 'interval'],
    'unknown interval' => [['interval' => 'decade'], 'interval'],
    'wrong currency' => [['currency' => 'eur'], 'currency'],
    'interval count zero' => [['interval_count' => 0], 'interval_count'],
]);

it('FR-PL10 caps the number of live plans per provider using the admin limit, ignoring archived ones', function () {
    [, $provider] = providerOwnerSession();
    app(SettingsService::class)->set('provider.max_plans', 2);
    makePlan($provider);
    makePlan($provider, ['status' => 'archived']);

    $this->postJson('/api/v1/provider/plans', planPayload())->assertCreated();
    $this->postJson('/api/v1/provider/plans', planPayload())->assertStatus(409)->assertJsonPath('code', 'plan_limit_reached');
});

it('FR-P5 will not publish a plan until Stripe is enabled for the provider', function () {
    [, $provider] = providerOwnerSession(['stripe_account_id' => 'acct_x', 'stripe_status' => 'restricted']);
    $plan = makePlan($provider);
    makePrice($plan);

    $this->postJson("/api/v1/provider/plans/{$plan->id}/publish")->assertStatus(409)->assertJsonPath('code', 'stripe_not_enabled');
    expect($plan->fresh()->status)->toBe('draft')->and(fakeStripe()->calls)->toBe([]);
});

it('FR-P5 will not publish for a provider that is paused', function () {
    [, $provider] = stripeReady(['status' => ProviderStatus::Suspended]);
    $plan = makePlan($provider);
    makePrice($plan);

    $this->postJson("/api/v1/provider/plans/{$plan->id}/publish")->assertStatus(409)->assertJsonPath('code', 'provider_not_approved');
});

it('FR-PL2 will not publish a plan without a price', function () {
    [, $provider] = stripeReady();
    $plan = makePlan($provider);

    $this->postJson("/api/v1/provider/plans/{$plan->id}/publish")->assertStatus(409)->assertJsonPath('code', 'plan_needs_price');
});

it('FR-PL4 publishes by creating the product and prices on the connected account with idempotency keys', function () {
    [, $provider] = stripeReady();
    $plan = makePlan($provider, ['name' => 'Pro', 'description' => 'Everything']);
    $monthly = makePrice($plan, ['amount' => 1900, 'interval' => 'month']);
    $yearly = makePrice($plan, ['amount' => 19000, 'interval' => 'year']);

    $this->postJson("/api/v1/provider/plans/{$plan->id}/publish")->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.prices.0.synced', true);

    $stripe = fakeStripe();
    $product = $stripe->callsTo('createProduct')[0];
    expect($product['account'])->toBe('acct_ready')
        ->and($product['params'])->toMatchArray(['name' => 'Pro', 'description' => 'Everything'])
        ->and($product['params']['metadata'])->toBe(['plan_id' => $plan->id, 'provider_id' => $provider->id])
        ->and($product['idempotency_key'])->toBe("plan-{$plan->id}-product");

    $prices = $stripe->callsTo('createPrice');
    expect($prices)->toHaveCount(2)
        ->and($prices[0]['account'])->toBe('acct_ready')
        ->and($prices[0]['params'])->toMatchArray(['unit_amount' => 1900, 'currency' => 'usd', 'recurring' => ['interval' => 'month', 'interval_count' => 1]])
        ->and($prices[0]['params']['product'])->toBe($plan->fresh()->stripe_product_id)
        ->and($prices[0]['idempotency_key'])->toBe("price-{$monthly->id}")
        ->and($prices[1]['idempotency_key'])->toBe("price-{$yearly->id}");
    expect($monthly->fresh()->stripe_price_id)->toStartWith('price_fake');
});

it('FR-PL4 publishing twice does not create a second product or price', function () {
    [, $provider] = stripeReady();
    $plan = makePlan($provider);
    makePrice($plan);

    $this->postJson("/api/v1/provider/plans/{$plan->id}/publish")->assertOk();
    $this->postJson("/api/v1/provider/plans/{$plan->id}/publish")->assertStatus(409)->assertJsonPath('code', 'plan_already_active');

    expect(fakeStripe()->callsTo('createProduct'))->toHaveCount(1)->and(fakeStripe()->callsTo('createPrice'))->toHaveCount(1);
});

it('FR-PL4 syncs a new price straight away when the plan is already active', function () {
    [, $provider] = stripeReady();
    $plan = makePlan($provider, ['status' => 'active', 'stripe_product_id' => 'prod_live']);

    $this->postJson("/api/v1/provider/plans/{$plan->id}/prices", pricePayload(['amount' => 4900]))->assertCreated()->assertJsonPath('data.synced', true);

    expect(fakeStripe()->callsTo('createPrice')[0]['params']['product'])->toBe('prod_live');
});

it('FR-PL4 pushes a rename of an active plan to Stripe', function () {
    [, $provider] = stripeReady();
    $plan = makePlan($provider, ['status' => 'active', 'stripe_product_id' => 'prod_live']);

    $this->patchJson("/api/v1/provider/plans/{$plan->id}", ['name' => 'Pro Plus'])->assertOk();

    $update = fakeStripe()->callsTo('updateProduct')[0];
    expect($update['product'])->toBe('prod_live')->and($update['params'])->toMatchArray(['name' => 'Pro Plus']);
});

it('FR-PL4 edits an unsynced price in place', function () {
    [, $provider] = providerOwnerSession();
    $plan = makePlan($provider);
    $price = makePrice($plan, ['amount' => 1000]);

    $this->patchJson("/api/v1/provider/plans/{$plan->id}/prices/{$price->id}", ['amount' => 1500])->assertOk()->assertJsonPath('data.id', $price->id)->assertJsonPath('data.amount', 1500);

    expect(Price::count())->toBe(1)->and(fakeStripe()->calls)->toBe([]);
});

it('FR-PL4 never mutates a price that exists on Stripe: it creates a new one and archives the old', function () {
    [, $provider] = stripeReady();
    $plan = makePlan($provider, ['status' => 'active', 'stripe_product_id' => 'prod_live']);
    $old = makePrice($plan, ['amount' => 1000, 'stripe_price_id' => 'price_old']);

    $response = $this->patchJson("/api/v1/provider/plans/{$plan->id}/prices/{$old->id}", ['amount' => 1500])->assertOk();

    expect($response->json('data.id'))->not->toBe($old->id)
        ->and($response->json('data.amount'))->toBe(1500)
        ->and($response->json('data.synced'))->toBeTrue()
        ->and($old->fresh()->status)->toBe('archived')
        ->and($old->fresh()->amount)->toBe(1000);

    $stripe = fakeStripe();
    expect($stripe->callsTo('archivePrice')[0]['price'])->toBe('price_old')
        ->and($stripe->callsTo('createPrice')[0]['params']['unit_amount'])->toBe(1500);
});

it('FR-PL4 archives a price without deleting it, and keeps the last live price of an active plan', function () {
    [, $provider] = stripeReady();
    $plan = makePlan($provider, ['status' => 'active', 'stripe_product_id' => 'prod_live']);
    $first = makePrice($plan, ['stripe_price_id' => 'price_a']);
    $second = makePrice($plan, ['stripe_price_id' => 'price_b']);

    $this->deleteJson("/api/v1/provider/plans/{$plan->id}/prices/{$first->id}")->assertNoContent();
    expect($first->fresh()->status)->toBe('archived')->and(Price::withTrashed()->count())->toBe(2)->and(fakeStripe()->callsTo('archivePrice')[0]['price'])->toBe('price_a');

    $this->deleteJson("/api/v1/provider/plans/{$plan->id}/prices/{$second->id}")->assertStatus(409)->assertJsonPath('code', 'plan_needs_price');
});

it('FR-PL5 archives a plan: stops new signups, keeps the record, hides the product on Stripe', function () {
    [, $provider] = stripeReady();
    $plan = makePlan($provider, ['status' => 'active', 'stripe_product_id' => 'prod_live']);

    $this->postJson("/api/v1/provider/plans/{$plan->id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');

    expect(Plan::withTrashed()->find($plan->id))->not->toBeNull()
        ->and(fakeStripe()->callsTo('updateProduct')[0]['params'])->toBe(['active' => false]);
    $this->postJson("/api/v1/provider/plans/{$plan->id}/publish")->assertStatus(409)->assertJsonPath('code', 'plan_archived');
});

it('FR-L3 deletes only drafts (soft), and tells the owner to archive anything that was live', function () {
    [, $provider] = providerOwnerSession();
    $draft = makePlan($provider);
    $live = makePlan($provider, ['status' => 'active', 'stripe_product_id' => 'prod_live']);

    $this->deleteJson("/api/v1/provider/plans/{$live->id}")->assertStatus(409)->assertJsonPath('code', 'plan_in_use');
    $this->deleteJson("/api/v1/provider/plans/{$draft->id}")->assertNoContent();

    expect(Plan::find($draft->id))->toBeNull()->and(Plan::withTrashed()->find($draft->id))->not->toBeNull();
});

it('FR-AD7 stores the new price limits as admin settings with sensible defaults', function () {
    $settings = app(SettingsService::class);

    expect($settings->get('provider.min_price'))->toBe(100)->and($settings->get('provider.max_price'))->toBe(1000000);
});

it('FR-PM9 asks Stripe for tax-exclusive prices only when the platform and the provider both turned tax on', function (bool $platform, bool $provider, bool $expected) {
    app(SettingsService::class)->set('stripe.tax_enabled', $platform);
    [, $model] = stripeReady(['settings' => ['tax' => ['enabled' => $provider]]]);
    $plan = makePlan($model);
    makePrice($plan);

    $this->postJson("/api/v1/provider/plans/{$plan->id}/publish")->assertOk();

    $params = fakeStripe()->callsTo('createPrice')[0]['params'];
    expect(array_key_exists('tax_behavior', $params))->toBe($expected);
    if ($expected) {
        expect($params['tax_behavior'])->toBe('exclusive');
    }
})->with([
    'both on' => [true, true, true],
    'platform only' => [true, false, false],
    'provider only' => [false, true, false],
    'both off' => [false, false, false],
]);
