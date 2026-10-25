<?php

use App\Models\Activity;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Payment;
use App\Models\Provider;

it('FR-CM1 an admin sets the global default and the provider override, and history is kept', function () {
    $admin = adminSession();
    $provider = Provider::factory()->create(['name' => 'Acme']);

    $this->postJson('/api/v1/admin/commission/rules', ['percent' => 10])->assertCreated()
        ->assertJsonPath('data.percent', '10.00')->assertJsonPath('data.provider_id', null);
    $this->postJson('/api/v1/admin/commission/rules', ['percent' => 12.5, 'provider_id' => $provider->id])->assertCreated()
        ->assertJsonPath('data.provider.name', 'Acme');
    $this->postJson('/api/v1/admin/commission/rules', ['percent' => 8])->assertCreated();

    $this->getJson('/api/v1/admin/commission/rules')->assertOk()->assertJsonPath('meta.total', 3);
    $this->getJson('/api/v1/admin/commission/rules?scope=global')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson("/api/v1/admin/commission/rules?provider_id={$provider->id}")->assertOk()->assertJsonPath('meta.total', 1);

    expect(CommissionRule::first()->created_by)->toBe($admin->id)
        ->and(Activity::where('description', 'commission.rule_created')->count())->toBe(3);
});

it('FR-CM2 validates a rule: percent 0-100, known provider, and a date', function (array $payload, string $field) {
    adminSession();

    $this->postJson('/api/v1/admin/commission/rules', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing percent' => [[], 'percent'],
    'over 100' => [['percent' => 101], 'percent'],
    'negative' => [['percent' => -1], 'percent'],
    'too many decimals' => [['percent' => 1.234], 'percent'],
    'unknown provider' => [['percent' => 5, 'provider_id' => '00000000-0000-0000-0000-000000000000'], 'provider_id'],
    'bad date' => [['percent' => 5, 'effective_from' => 'tomorrowish'], 'effective_from'],
]);

it('FR-CM2 a rule that has not started yet can be removed; one that has started cannot', function () {
    adminSession();
    $future = CommissionRule::factory()->create(['effective_from' => now()->addWeek()]);
    $past = CommissionRule::factory()->create(['effective_from' => now()->subWeek()]);

    $this->deleteJson("/api/v1/admin/commission/rules/{$future->id}")->assertNoContent();
    $this->deleteJson("/api/v1/admin/commission/rules/{$past->id}")->assertStatus(409)->assertJsonPath('code', 'rule_already_effective');

    expect(CommissionRule::count())->toBe(1);
});

it('FR-CM2 only admins manage commission rules', function () {
    providerOwnerSession();

    $this->getJson('/api/v1/admin/commission/rules')->assertForbidden();
    $this->postJson('/api/v1/admin/commission/rules', ['percent' => 1])->assertForbidden();
});

it('FR-CM4 the admin report lists commissions with totals per currency and can filter by provider', function () {
    adminSession();
    $a = Provider::factory()->create(['name' => 'Acme']);
    $b = Provider::factory()->create(['name' => 'Beta']);
    Commission::factory()->create(['provider_id' => $a->id, 'gross_amount' => 2000, 'commission_amount' => 200, 'refunded_amount' => 1000, 'commission_refunded' => 100]);
    Commission::factory()->create(['provider_id' => $a->id, 'gross_amount' => 3000, 'commission_amount' => 300]);
    Commission::factory()->create(['provider_id' => $b->id, 'gross_amount' => 1000, 'commission_amount' => 100]);

    $this->getJson('/api/v1/admin/commission/report')->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('totals.0.currency', 'usd')
        ->assertJsonPath('totals.0.gross', 6000)
        ->assertJsonPath('totals.0.refunded', 1000)
        ->assertJsonPath('totals.0.commission', 600)
        ->assertJsonPath('totals.0.commission_refunded', 100)
        ->assertJsonPath('totals.0.net_commission', 500);

    $this->getJson("/api/v1/admin/commission/report?provider_id={$a->id}")->assertOk()
        ->assertJsonPath('meta.total', 2)->assertJsonPath('totals.0.net_commission', 400)
        ->assertJsonPath('data.0.provider.name', 'Acme');
});

it('FR-CM4 a provider sees gross, fee and net on each payment, and the totals on the overview', function () {
    [, $provider] = providerOwnerSession(['stripe_account_id' => 'acct_x', 'stripe_status' => 'enabled']);
    $payment = Payment::factory()->create(['provider_id' => $provider->id, 'amount' => 2000, 'amount_refunded' => 500]);
    Commission::factory()->create(['provider_id' => $provider->id, 'payment_id' => $payment->id, 'gross_amount' => 2000, 'commission_amount' => 200,
        'refunded_amount' => 500, 'commission_refunded' => 50, 'rate_percent' => 10]);
    $free = Payment::factory()->create(['provider_id' => $provider->id, 'amount' => 1000]);

    $rows = collect($this->getJson('/api/v1/provider/payments')->assertOk()->json('data'))->keyBy('id');

    expect($rows[$payment->id]['commission'])->toBe(['rate_percent' => '10.00', 'amount' => 200, 'refunded' => 50, 'net_fee' => 150, 'provider_net' => 1350])
        ->and($rows[$free->id]['commission'])->toBeNull();

    $this->getJson('/api/v1/provider/overview')->assertOk()
        ->assertJsonPath('data.commission.fee_30d', 150)
        ->assertJsonPath('data.commission.rate_percent', '0.00');
});

it('FR-CM4 a provider never sees another provider\'s commissions', function () {
    [, $mine] = providerOwnerSession();
    $other = Provider::factory()->create();
    $payment = Payment::factory()->create(['provider_id' => $other->id]);
    Commission::factory()->create(['provider_id' => $other->id, 'payment_id' => $payment->id]);

    expect(collect($this->getJson('/api/v1/provider/payments')->assertOk()->json('data'))->pluck('commission')->filter()->all())->toBe([]);
});
