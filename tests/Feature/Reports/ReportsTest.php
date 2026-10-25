<?php

use App\Models\Commission;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

function paidOn(Provider $provider, string $date, int $amount, array $overrides = []): Payment
{
    return Payment::factory()->create(array_merge([
        'provider_id' => $provider->id, 'status' => 'succeeded', 'amount' => $amount, 'amount_refunded' => 0, 'paid_at' => Carbon::parse($date), 'created_at' => Carbon::parse($date),
    ], $overrides));
}

it('FR-AD13 the admin revenue report groups paid payments by month and currency with refunds and fees', function () {
    adminSession();
    $a = Provider::factory()->create();
    $b = Provider::factory()->create();
    $p1 = paidOn($a, '2026-09-10', 2000, ['amount_refunded' => 500, 'status' => 'partially_refunded']);
    Commission::factory()->create(['provider_id' => $a->id, 'payment_id' => $p1->id, 'gross_amount' => 2000, 'commission_amount' => 200, 'refunded_amount' => 500, 'commission_refunded' => 50]);
    paidOn($b, '2026-09-20', 3000);
    paidOn($b, '2026-10-02', 1000);
    paidOn($b, '2026-10-03', 9999, ['status' => 'failed']); // never counted as revenue
    paidOn($b, '2026-10-04', 700, ['currency' => 'eur']);

    $rows = collect($this->getJson('/api/v1/admin/reports/revenue?group=month&from=2026-09-01&to=2026-10-31')->assertOk()->json('data'))->keyBy(fn ($r) => $r['period'].' '.$r['currency']);

    expect($rows)->toHaveCount(3)
        ->and($rows['2026-09 usd'])->toMatchArray(['payments' => 2, 'gross' => 5000, 'refunded' => 500, 'net_revenue' => 4500, 'commission' => 150])
        ->and($rows['2026-10 usd'])->toMatchArray(['payments' => 1, 'gross' => 1000, 'refunded' => 0, 'net_revenue' => 1000, 'commission' => 0])
        ->and($rows['2026-10 eur']['gross'])->toBe(700);
});

it('FR-AD13 reports can be grouped by day and narrowed to one provider', function () {
    adminSession();
    $a = Provider::factory()->create();
    $b = Provider::factory()->create();
    paidOn($a, '2026-10-01', 1000);
    paidOn($a, '2026-10-01', 500);
    paidOn($a, '2026-10-02', 100);
    paidOn($b, '2026-10-01', 9000);

    $rows = $this->getJson("/api/v1/admin/reports/revenue?group=day&provider_id={$a->id}&from=2026-10-01&to=2026-10-31")->assertOk()->json('data');

    expect(collect($rows)->pluck('gross', 'period')->all())->toBe(['2026-10-01' => 1500, '2026-10-02' => 100]);
});

it('FR-AD13 the admin churn report counts new and canceled subscriptions per month', function () {
    adminSession();
    $provider = Provider::factory()->create();
    Subscription::factory()->create(['provider_id' => $provider->id, 'status' => 'active', 'created_at' => Carbon::parse('2026-10-05')]);
    Subscription::factory()->create(['provider_id' => $provider->id, 'status' => 'active', 'created_at' => Carbon::parse('2026-10-06')]);
    Subscription::factory()->create(['provider_id' => $provider->id, 'status' => 'canceled', 'created_at' => Carbon::parse('2026-09-06'), 'ended_at' => Carbon::parse('2026-10-09')]);
    Subscription::factory()->create(['provider_id' => $provider->id, 'status' => 'incomplete', 'created_at' => Carbon::parse('2026-10-06')]); // never paid, not a subscriber

    $rows = collect($this->getJson('/api/v1/admin/reports/churn?from=2026-09-01&to=2026-10-31')->assertOk()->json('data'))->keyBy('period');

    expect($rows['2026-10'])->toMatchArray(['new' => 2, 'canceled' => 1])
        ->and($rows['2026-09'])->toMatchArray(['new' => 1, 'canceled' => 0]);
});

it('FR-AD13 the failed-payments report counts failures by reason', function () {
    adminSession();
    $provider = Provider::factory()->create();
    paidOn($provider, '2026-10-01', 1000, ['status' => 'failed', 'failure_code' => 'insufficient_funds']);
    paidOn($provider, '2026-10-02', 1000, ['status' => 'failed', 'failure_code' => 'insufficient_funds']);
    paidOn($provider, '2026-10-03', 1000, ['status' => 'failed', 'failure_code' => 'expired_card']);
    paidOn($provider, '2026-10-03', 1000);

    $rows = collect($this->getJson('/api/v1/admin/reports/failures?from=2026-10-01&to=2026-10-31')->assertOk()->json('data'));

    expect($rows->pluck('count', 'reason')->all())->toBe(['insufficient_funds' => 2, 'expired_card' => 1]);
});

it('FR-PD1 a provider gets the same revenue report for their own business only', function () {
    [, $mine] = providerOwnerSession();
    $other = Provider::factory()->create();
    paidOn($mine, '2026-10-01', 1200);
    paidOn($other, '2026-10-01', 7700);

    $rows = $this->getJson('/api/v1/provider/reports/revenue?group=month&from=2026-10-01&to=2026-10-31')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)->and($rows[0]['gross'])->toBe(1200);
});

it('FR-AD13 exports a report as CSV, with a header row and escaped values', function () {
    adminSession();
    $provider = Provider::factory()->create(['name' => 'Acme, "Best" Fitness']);
    paidOn($provider, '2026-10-01', 1000);

    $response = $this->get('/api/v1/admin/reports/export?type=revenue&group=month&from=2026-10-01&to=2026-10-31', ['Accept' => 'text/csv'])->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment; filename=revenue-2026-10-01-to-2026-10-31.csv');
    $lines = array_filter(explode("\n", trim($response->streamedContent())));
    expect($lines[0])->toBe('period,currency,payments,gross,refunded,net_revenue,commission')
        ->and($lines[1])->toBe('2026-10,usd,1,1000,0,1000,0');
});

it('FR-AD13 the payments export lists one row per payment and neutralises spreadsheet formulas', function () {
    adminSession();
    $provider = Provider::factory()->create(['name' => '=HYPERLINK("http://evil")']);
    paidOn($provider, '2026-10-01', 1000);

    $csv = $this->get('/api/v1/admin/reports/export?type=payments&from=2026-10-01&to=2026-10-31')->assertOk()->streamedContent();

    expect($csv)->toContain('date,provider,status,currency,amount,refunded,failure_code')
        ->and($csv)->toContain("'=HYPERLINK") // a leading = would run as a formula in Excel
        ->and($csv)->not->toContain(',=HYPERLINK');
});

it('FR-AD13 validates the report filters and the export type', function () {
    adminSession();

    $this->getJson('/api/v1/admin/reports/revenue?group=year')->assertUnprocessable()->assertJsonValidationErrors('group');
    $this->getJson('/api/v1/admin/reports/revenue?from=2026-10-31&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->getJson('/api/v1/admin/reports/export?type=secrets')->assertUnprocessable()->assertJsonValidationErrors('type');
});

it('FR-AD13 reports are for admins and providers, not customers', function () {
    customerSession();

    $this->getJson('/api/v1/admin/reports/revenue')->assertForbidden();
    $this->getJson('/api/v1/provider/reports/revenue')->assertNotFound(); // no provider account
});
