<?php

use App\Enums\ProviderStatus;
use App\Models\Plan;
use App\Models\Provider;
use App\Models\User;
use App\Services\Storefront\StorefrontService;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => config(['app.base_domain' => 'platform.test']));

function publishedStorefront(): Provider
{
    $provider = providerWithDraft([block('hero', ['headline' => 'Train smarter'])]);
    test()->postJson('/api/v1/provider/storefront/publish')->assertOk();
    app('auth')->forgetGuards();

    return $provider;
}

function queriesDuring(callable $request): int
{
    DB::enableQueryLog();
    DB::flushQueryLog();
    $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('FR-L2 serves a published storefront from the cache on the second visit', function () {
    publishedStorefront();

    $first = queriesDuring(fn () => storefront()->assertOk());
    $second = queriesDuring(fn () => storefront()->assertOk());

    expect($second)->toBeLessThan($first)->and($second)->toBeLessThanOrEqual(2); // the tenant lookup only
});

it('FR-L2 an edit to the page shows up immediately after publishing', function () {
    publishedStorefront();
    storefront()->assertJsonPath('data.page.blocks.0.data.headline', 'Train smarter');

    $this->withToken(loginAs(User::first())->json('data.access_token'));
    app('auth')->forgetGuards();
    $this->putJson('/api/v1/provider/storefront', ['seo' => ['title' => 'Acme'], 'blocks' => [block('hero', ['headline' => 'New headline'])]])->assertOk();
    $this->postJson('/api/v1/provider/storefront/publish')->assertOk();

    storefront()->assertJsonPath('data.page.blocks.0.data.headline', 'New headline');
});

it('FR-L2 a new or archived plan shows up immediately', function () {
    $provider = publishedStorefront();
    storefront()->assertJsonPath('data.plans', []);

    $plan = Plan::factory()->create(['provider_id' => $provider->id, 'status' => 'active', 'visibility' => 'public', 'name' => 'Gold']);
    $plan->prices()->create(['provider_id' => $provider->id, 'amount' => 2000, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_1']);
    storefront()->assertJsonPath('data.plans.0.name', 'Gold');

    $plan->update(['status' => 'archived']);
    storefront()->assertJsonPath('data.plans', []);
});

it('FR-L2 pausing the provider takes the storefront offline at once', function () {
    $provider = publishedStorefront();
    storefront()->assertJsonPath('data.available', true);

    $provider->update(['status' => ProviderStatus::Suspended]);

    storefront()->assertJsonPath('data.available', false)->assertJsonPath('data.page', null);
});

it('FR-L2 a renamed provider shows the new name immediately', function () {
    $provider = publishedStorefront();
    storefront()->assertJsonPath('data.provider.name', 'Acme Gym');

    $provider->update(['name' => 'Acme Fitness']);

    storefront()->assertJsonPath('data.provider.name', 'Acme Fitness');
});

it('FR-S3 never serves or stores a draft through the cache', function () {
    $provider = publishedStorefront();
    storefront()->assertOk(); // warm the cache with the public view

    $token = app(StorefrontService::class)->previewToken($provider);
    $preview = storefront(query: "?preview={$token}")->assertOk()->assertJsonPath('data.preview', true);

    expect($preview->json('data.preview'))->toBeTrue();
    storefront()->assertJsonPath('data.preview', false); // the public view was not replaced by the preview
});

it('FR-L2 one provider\'s cached storefront is never served for another', function () {
    publishedStorefront();
    storefront()->assertJsonPath('data.provider.slug', 'acme');
    Provider::factory()->create(['slug' => 'beta', 'name' => 'Beta Gym', 'status' => ProviderStatus::Active]);

    storefront('beta.platform.test')->assertJsonPath('data.provider.slug', 'beta');
});
