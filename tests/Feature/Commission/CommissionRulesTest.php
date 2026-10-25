<?php

use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Provider;
use App\Services\Commission\CommissionResolver;

it('FR-CM1 the global default applies to every provider, and is zero when nothing is set', function () {
    $provider = Provider::factory()->create();
    expect(app(CommissionResolver::class)->rateFor($provider))->toBe('0.00');

    CommissionRule::factory()->create(['percent' => 10]);

    expect(app(CommissionResolver::class)->rateFor($provider))->toBe('10.00');
});

it('FR-CM2 a provider override beats the global default', function () {
    $special = Provider::factory()->create();
    $other = Provider::factory()->create();
    CommissionRule::factory()->create(['percent' => 10]);
    CommissionRule::factory()->create(['provider_id' => $special->id, 'percent' => 4.5]);

    expect(app(CommissionResolver::class)->rateFor($special))->toBe('4.50')
        ->and(app(CommissionResolver::class)->rateFor($other))->toBe('10.00');
});

it('FR-CM2 resolves by effective date: the latest rule that has started wins and history is kept', function () {
    $provider = Provider::factory()->create();
    CommissionRule::factory()->create(['percent' => 10, 'effective_from' => now()->subMonths(2)]);
    CommissionRule::factory()->create(['percent' => 12, 'effective_from' => now()->subDay()]);
    CommissionRule::factory()->create(['percent' => 15, 'effective_from' => now()->addMonth()]); // not yet

    $resolver = app(CommissionResolver::class);

    expect($resolver->rateFor($provider))->toBe('12.00')
        ->and($resolver->rateFor($provider, now()->subMonth()))->toBe('10.00')
        ->and($resolver->rateFor($provider, now()->addMonths(2)))->toBe('15.00')
        ->and($resolver->rateFor($provider, now()->subYear()))->toBe('0.00')
        ->and(CommissionRule::count())->toBe(3);
});

it('FR-CM2 a provider rule that has not started yet falls back to the global rate', function () {
    $provider = Provider::factory()->create();
    CommissionRule::factory()->create(['percent' => 10]);
    CommissionRule::factory()->create(['provider_id' => $provider->id, 'percent' => 3, 'effective_from' => now()->addWeek()]);

    expect(app(CommissionResolver::class)->rateFor($provider))->toBe('10.00');
});

it('FR-CM3 isolates commissions per provider', function () {
    assertTenantIsolation(Commission::class, fn (Provider $p) => Commission::factory()->create(['provider_id' => $p->id]));
});
