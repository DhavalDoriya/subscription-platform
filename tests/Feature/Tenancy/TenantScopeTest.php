<?php

use App\Models\Plan;
use App\Models\Price;
use App\Models\Provider;
use App\Models\ProviderCustomer;
use App\Support\Tenancy\TenantContext;

/*
 * T2.14: assertTenantIsolation() is the reusable check every later tenant-owned model must pass.
 * Pass the model class and a closure that creates one row for a given provider.
 */
function assertTenantIsolation(string $model, Closure $makeFor): void
{
    $a = Provider::factory()->create();
    $b = Provider::factory()->create();
    $rowA = $makeFor($a);
    $rowB = $makeFor($b);
    $tenancy = app(TenantContext::class);

    // No tenant (system, admin): everything is visible.
    expect($model::count())->toBe(2);

    $tenancy->run($a, function () use ($model, $rowA, $rowB) {
        expect($model::count())->toBe(1)
            ->and($model::find($rowA->id))->not->toBeNull()
            ->and($model::find($rowB->id))->toBeNull()
            ->and($model::where('id', $rowB->id)->exists())->toBeFalse();
    });

    $tenancy->run($b, fn () => expect($model::pluck('id')->all())->toBe([$rowB->id]));
    expect($tenancy->current())->toBeNull(); // restored after run()

    // Rows created inside a tenant belong to it, even if the caller tries another provider.
    $tenancy->run($a, function () use ($model, $b) {
        $created = $model::factory()->create(['provider_id' => $b->id]);
        expect($created->provider_id)->toBe(app(TenantContext::class)->current()->id);
    });
}

it('FR-NFR provider customers are isolated per tenant', function () {
    assertTenantIsolation(ProviderCustomer::class, fn (Provider $p) => ProviderCustomer::factory()->create(['provider_id' => $p->id]));
});

it('FR-NFR the tenant context can be set and cleared explicitly', function () {
    $tenancy = app(TenantContext::class);
    $provider = Provider::factory()->create();

    $tenancy->set($provider);
    expect($tenancy->current()->is($provider))->toBeTrue();

    $tenancy->forget();
    expect($tenancy->current())->toBeNull();
});

it('FR-PL1 plans and prices are isolated per tenant', function () {
    assertTenantIsolation(Plan::class, fn (Provider $p) => Plan::factory()->create(['provider_id' => $p->id]));
    assertTenantIsolation(Price::class, fn (Provider $p) => Price::factory()->create(['provider_id' => $p->id, 'plan_id' => Plan::factory()->create(['provider_id' => $p->id])->id]));
});
