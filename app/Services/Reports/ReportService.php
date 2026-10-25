<?php

namespace App\Services\Reports;

use App\Models\Commission;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Revenue, churn, failed-payment and commission numbers for a date range (FR-AD13, FR-PD1). The queries run in the
 * caller's tenant scope: a provider sees their own business, an admin everything (optionally narrowed to one provider).
 */
class ReportService
{
    /** Payments in these statuses brought money in; refunds are netted off by the `refunded` column. */
    private const PAID = ['succeeded', 'partially_refunded', 'refunded'];

    /** @return array<int, array{period: string, currency: string, payments: int, gross: int, refunded: int, net_revenue: int, commission: int}> */
    public function revenue(Carbon $from, Carbon $to, string $group, ?string $providerId = null): array
    {
        $period = $this->period('payments.paid_at', $group);

        $payments = $this->scoped(Payment::query(), 'payments.provider_id', $providerId)->whereIn('payments.status', self::PAID)
            ->whereBetween('payments.paid_at', [$from, $to])
            ->selectRaw("{$period} as period, payments.currency, count(*) as payments, sum(payments.amount) as gross, sum(payments.amount_refunded) as refunded")
            ->groupBy('period', 'payments.currency')->get();

        $fees = $this->scoped(Commission::query(), 'commissions.provider_id', $providerId)->join('payments', 'payments.id', '=', 'commissions.payment_id')
            ->whereBetween('payments.paid_at', [$from, $to])
            ->selectRaw("{$period} as period, commissions.currency, sum(commissions.commission_amount - commissions.commission_refunded) as commission")
            ->groupBy('period', 'commissions.currency')->get()
            ->mapWithKeys(fn ($row) => [$row->period.'|'.$row->currency => (int) $row->commission]);

        return $payments->map(fn ($row) => [
            'period' => $row->period,
            'currency' => $row->currency,
            'payments' => (int) $row->payments,
            'gross' => (int) $row->gross,
            'refunded' => (int) $row->refunded,
            'net_revenue' => (int) $row->gross - (int) $row->refunded,
            'commission' => $fees[$row->period.'|'.$row->currency] ?? 0,
        ])->sortBy([['period', 'asc'], ['currency', 'asc']])->values()->all();
    }

    /** @return array<int, array{period: string, new: int, canceled: int}> subscribers gained and lost per month */
    public function churn(Carbon $from, Carbon $to, ?string $providerId = null): array
    {
        $month = fn (string $column) => $this->period($column, 'month');
        $counted = fn () => $this->scoped(Subscription::query(), 'subscriptions.provider_id', $providerId);

        $new = $counted()->whereNotIn('status', ['incomplete', 'expired'])->whereBetween('created_at', [$from, $to])
            ->selectRaw("{$month('created_at')} as period, count(*) as total")->groupBy('period')->pluck('total', 'period');
        $canceled = $counted()->where('status', 'canceled')->whereBetween('ended_at', [$from, $to])
            ->selectRaw("{$month('ended_at')} as period, count(*) as total")->groupBy('period')->pluck('total', 'period');

        return collect($new->keys()->merge($canceled->keys())->unique()->sort()->values())
            ->map(fn (string $period) => ['period' => $period, 'new' => (int) ($new[$period] ?? 0), 'canceled' => (int) ($canceled[$period] ?? 0)])->all();
    }

    /** @return array<int, array{reason: string, count: int, amount: int}> failed payments by decline reason, most frequent first */
    public function failures(Carbon $from, Carbon $to, ?string $providerId = null): array
    {
        return $this->scoped(Payment::query(), 'payments.provider_id', $providerId)->where('status', 'failed')->whereBetween('created_at', [$from, $to])
            ->selectRaw("coalesce(failure_code, 'unknown') as reason, count(*) as total, sum(amount) as amount")
            ->groupBy('reason')->orderByDesc('total')->orderBy('reason')->get()
            ->map(fn ($row) => ['reason' => $row->reason, 'count' => (int) $row->total, 'amount' => (int) $row->amount])->all();
    }

    /** @return LazyCollection<int, array<string, mixed>> one row per payment */
    public function payments(Carbon $from, Carbon $to, ?string $providerId = null)
    {
        return $this->scoped(Payment::query(), 'payments.provider_id', $providerId)->with('provider:id,name')->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')->cursor()
            ->map(fn (Payment $p) => [
                'date' => $p->created_at->toDateString(), 'provider' => $p->provider?->name, 'status' => $p->status, 'currency' => $p->currency,
                'amount' => $p->amount, 'refunded' => (int) $p->amount_refunded, 'failure_code' => $p->failure_code,
            ]);
    }

    /** @return LazyCollection<int, array<string, mixed>> one row per commission */
    public function commissions(Carbon $from, Carbon $to, ?string $providerId = null)
    {
        return $this->scoped(Commission::query(), 'commissions.provider_id', $providerId)->with('provider:id,name')->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')->cursor()
            ->map(fn (Commission $c) => [
                'date' => $c->created_at->toDateString(), 'provider' => $c->provider?->name, 'currency' => $c->currency, 'gross' => $c->gross_amount,
                'refunded' => $c->refunded_amount, 'rate_percent' => (string) $c->rate_percent, 'commission' => $c->commission_amount,
                'commission_refunded' => $c->commission_refunded, 'net_commission' => $c->netCommission(),
            ]);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scoped(Builder $query, string $column, ?string $providerId): Builder
    {
        return $query->when($providerId, fn (Builder $q) => $q->where($column, $providerId));
    }

    /** SQL that turns a timestamp column into `YYYY-MM` or `YYYY-MM-DD` on both SQLite (tests) and PostgreSQL. */
    private function period(string $column, string $group): string
    {
        $format = $group === 'day' ? ['%Y-%m-%d', 'YYYY-MM-DD'] : ['%Y-%m', 'YYYY-MM'];

        return DB::getDriverName() === 'sqlite' ? "strftime('{$format[0]}', {$column})" : "to_char({$column}, '{$format[1]}')";
    }
}
