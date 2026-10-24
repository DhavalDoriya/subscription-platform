<?php

use App\Enums\ProviderStatus;
use App\Models\Provider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => config(['app.base_domain' => 'platform.test']));

function onHost(string $host, string $path = '/api/v1/public/tenant'): TestResponse
{
    return test()->getJson("http://{$host}{$path}");
}

it('FR-P1 resolves the provider from the subdomain', function () {
    Provider::factory()->create(['slug' => 'acme', 'name' => 'Acme', 'status' => ProviderStatus::Active]);

    onHost('acme.platform.test')->assertOk()
        ->assertJsonPath('data.slug', 'acme')
        ->assertJsonPath('data.name', 'Acme')
        ->assertJsonPath('data.available', true);
});

it('FR-S9 marks inactive or suspended providers as unavailable', function (ProviderStatus $status) {
    Provider::factory()->create(['slug' => 'acme', 'status' => $status]);

    onHost('acme.platform.test')->assertOk()->assertJsonPath('data.available', false);
})->with([ProviderStatus::Inactive, ProviderStatus::Suspended, ProviderStatus::Pending, ProviderStatus::Rejected]);

it('FR-P1 returns 404 for a subdomain nobody owns', function () {
    onHost('ghost.platform.test')->assertNotFound()->assertJsonPath('code', 'tenant_not_found');
});

it('FR-P3 treats reserved subdomains and the bare domain as the main app, not a tenant', function (string $host) {
    onHost($host)->assertOk()->assertJsonPath('data', null);
})->with(['www.platform.test', 'admin.platform.test', 'api.platform.test', 'platform.test']);

it('FR-P1 ignores hosts outside the base domain', function () {
    Provider::factory()->create(['slug' => 'acme', 'status' => ProviderStatus::Active]);

    onHost('acme.evil.test')->assertOk()->assertJsonPath('data', null);
});

it('FR-P1 clears the tenant after the request', function () {
    Provider::factory()->create(['slug' => 'acme', 'status' => ProviderStatus::Active]);
    onHost('acme.platform.test')->assertOk();

    expect(app(TenantContext::class)->current())->toBeNull();
});
