<?php

use App\Enums\ProviderStatus;
use App\Models\Activity;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Provider;
use App\Models\User;
use App\Services\Storefront\StorefrontService;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => config(['app.base_domain' => 'platform.test']));

function storefront(string $host = 'acme.platform.test', string $query = ''): TestResponse
{
    return test()->withoutToken()->getJson("http://{$host}/api/v1/public/storefront{$query}");
}

/** A provider with a saved draft, set up through the real editor API. Returns [provider, session-bound test]. */
function providerWithDraft(array $blocks, array $provider = []): Provider
{
    [, $model] = providerOwnerSession(array_merge(['slug' => 'acme', 'status' => ProviderStatus::Active, 'name' => 'Acme Gym'], $provider));
    test()->putJson('/api/v1/provider/storefront', ['seo' => ['title' => 'Acme'], 'blocks' => $blocks])->assertOk();

    return $model;
}

it('FR-S3 shows nothing of a draft to the public', function () {
    providerWithDraft([block('hero', ['headline' => 'Secret draft'])]);

    $response = storefront()->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.published', false)->assertJsonPath('data.page', null);

    expect($response->getContent())->not->toContain('Secret draft');
});

it('FR-S1 serves the published page: provider, SEO, visible blocks in order', function () {
    providerWithDraft([block('hero', ['headline' => 'Train smarter']), block('about', [], ['visible' => false]), block('cta')]);
    $this->postJson('/api/v1/provider/storefront/publish')->assertOk();

    $response = storefront()->assertOk()
        ->assertJsonPath('data.available', true)->assertJsonPath('data.published', true)
        ->assertJsonPath('data.provider.name', 'Acme Gym')->assertJsonPath('data.provider.slug', 'acme')
        ->assertJsonPath('data.page.seo.title', 'Acme');

    expect(array_column($response->json('data.page.blocks'), 'type'))->toBe(['hero', 'cta'])
        ->and($response->json('data.page.blocks.0.data.headline'))->toBe('Train smarter');
});

it('FR-S3 keeps later draft edits private until they are published again', function () {
    providerWithDraft([block('hero', ['headline' => 'Version one'])]);
    $this->postJson('/api/v1/provider/storefront/publish')->assertOk();
    $this->putJson('/api/v1/provider/storefront', ['seo' => [], 'blocks' => [block('hero', ['headline' => 'Version two'])]])->assertOk();

    storefront()->assertJsonPath('data.page.blocks.0.data.headline', 'Version one');

    $this->postJson('/api/v1/provider/storefront/publish')->assertOk();
    storefront()->assertJsonPath('data.page.blocks.0.data.headline', 'Version two');
});

it('FR-S3 shows the draft to someone holding a valid preview link', function () {
    providerWithDraft([block('hero', ['headline' => 'Only in preview'])]);
    $token = $this->postJson('/api/v1/provider/storefront/preview')->json('data.token');

    storefront('acme.platform.test', "?preview={$token}")->assertOk()
        ->assertJsonPath('data.preview', true)->assertJsonPath('data.page.blocks.0.data.headline', 'Only in preview');
    storefront()->assertJsonPath('data.page', null);
});

it('FR-S3 ignores a preview token that is forged, expired or for another provider', function () {
    $acme = providerWithDraft([block('hero', ['headline' => 'Only in preview'])]);
    $other = Provider::factory()->create(['slug' => 'other', 'status' => ProviderStatus::Active]);

    $service = app(StorefrontService::class);
    $forTheOther = $service->previewToken($other);
    $expired = $service->previewToken($acme, time() - 1);
    $tampered = $service->previewToken($acme).'x';

    foreach (['garbage', $expired, $tampered] as $token) {
        storefront('acme.platform.test', "?preview={$token}")->assertOk()->assertJsonPath('data.preview', false)->assertJsonPath('data.page', null);
    }
    storefront('acme.platform.test', "?preview={$forTheOther}")->assertOk()->assertJsonPath('data.preview', false)->assertJsonPath('data.page', null);
});

it('FR-S9 shows an unavailable page, with no content, when the provider is paused', function (ProviderStatus $status) {
    $provider = providerWithDraft([block('hero', ['headline' => 'Hidden now'])]);
    $this->postJson('/api/v1/provider/storefront/publish')->assertOk();
    $provider->update(['status' => $status]);

    $response = storefront()->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.page', null)->assertJsonPath('data.provider.name', 'Acme Gym');

    expect($response->getContent())->not->toContain('Hidden now');
})->with([ProviderStatus::Inactive, ProviderStatus::Suspended]);

it('FR-S9 comes back when the provider is reactivated', function () {
    $provider = providerWithDraft([block('hero')]);
    $this->postJson('/api/v1/provider/storefront/publish')->assertOk();
    $provider->update(['status' => ProviderStatus::Suspended]);
    storefront()->assertJsonPath('data.available', false);

    $provider->update(['status' => ProviderStatus::Active]);
    storefront()->assertJsonPath('data.available', true)->assertJsonPath('data.published', true);
});

it('FR-S1 answers 404 for an unknown address and for the main domain', function () {
    storefront('ghost.platform.test')->assertNotFound()->assertJsonPath('code', 'tenant_not_found');
    storefront('platform.test')->assertNotFound();
});

it('FR-S1 lists only live, public plans with their live prices', function () {
    $provider = providerWithDraft([block('hero'), block('plans')]);
    $this->postJson('/api/v1/provider/storefront/publish')->assertOk();
    $live = Plan::factory()->create(['provider_id' => $provider->id, 'name' => 'Live', 'status' => 'active', 'sort_order' => 1]);
    Price::factory()->create(['provider_id' => $provider->id, 'plan_id' => $live->id, 'amount' => 1900]);
    Price::factory()->create(['provider_id' => $provider->id, 'plan_id' => $live->id, 'amount' => 900, 'status' => 'archived']);
    Plan::factory()->create(['provider_id' => $provider->id, 'name' => 'Draft', 'status' => 'draft']);
    Plan::factory()->create(['provider_id' => $provider->id, 'name' => 'Old', 'status' => 'archived']);
    Plan::factory()->create(['provider_id' => $provider->id, 'name' => 'Unlisted', 'status' => 'active', 'visibility' => 'unlisted']);
    Plan::factory()->create(['provider_id' => Provider::factory()->create()->id, 'name' => 'Someone else', 'status' => 'active']);

    $plans = storefront()->assertOk()->json('data.plans');

    expect(array_column($plans, 'name'))->toBe(['Live'])
        ->and(array_column($plans[0]['prices'], 'amount'))->toBe([1900])
        ->and($plans[0])->not->toHaveKey('stripe_product_id');
});

it('FR-S1 never leaks internal fields about the provider', function () {
    providerWithDraft([block('hero')]);
    $this->postJson('/api/v1/provider/storefront/publish')->assertOk();

    $json = storefront()->assertOk()->getContent();

    foreach (['stripe_account_id', 'stripe_status', 'rejection_reason', 'owner', 'member'] as $secret) {
        expect($json)->not->toContain($secret);
    }
});

it('FR-AD12 lets an admin unpublish a storefront that breaks the rules, and audits who did it', function () {
    $provider = providerWithDraft([block('hero')]);
    $this->postJson('/api/v1/provider/storefront/publish')->assertOk();
    $admin = User::factory()->create(['password' => 'Password123!'])->assignRole('admin');
    $this->withToken(loginAs($admin)->json('data.access_token'));
    app('auth')->forgetGuards();

    $this->postJson("/api/v1/admin/providers/{$provider->id}/storefront/unpublish")->assertOk()->assertJsonPath('data.published', false);

    storefront()->assertJsonPath('data.published', false);
    expect(Activity::where('description', 'storefront.moderated')->first()->causer_id)->toBe($admin->id);
});

it('FR-AD12 keeps storefront moderation behind admin roles', function () {
    $provider = Provider::factory()->create();
    $customer = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $this->withToken(loginAs($customer)->json('data.access_token'));

    $this->postJson("/api/v1/admin/providers/{$provider->id}/storefront/unpublish")->assertForbidden();
});
