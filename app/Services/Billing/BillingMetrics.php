<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\Subscription;
use App\Services\Commission\CommissionResolver;

/** The numbers on a provider's overview (FR-PD1). Everything is read from our mirror, in the provider's tenant scope. */
class BillingMetrics
{
    public function __construct(private CommissionResolver $commissions) {}

    /** @return array{currency: string, commission: array{rate_percent: string, fee_30d: int}, mrr: int, active_subscribers: int, new_30d: int, churned_30d: int, failed_payments_30d: int, upcoming_renewals_7d: int} */
    public function overview(Provider $provider): array
    {
        $since = now()->subDays(30);
        $withAccess = collect(SubscriptionStatus::cases())->filter->grantsAccess()->map->value->all();

        return [
            'currency' => $provider->currency,
            'mrr' => $this->mrr(),
            'active_subscribers' => Subscription::whereIn('status', $withAccess)->count(),
            'new_30d' => Subscription::where('created_at', '>=', $since)->whereNotIn('status', ['incomplete', 'expired'])->count(),
            'churned_30d' => Subscription::where('status', 'canceled')->where('ended_at', '>=', $since)->count(),
            'failed_payments_30d' => Payment::where('status', 'failed')->where('created_at', '>=', $since)->count(),
            'commission' => [
                'rate_percent' => $this->commissions->rateFor($provider),
                'fee_30d' => (int) Commission::where('created_at', '>=', $since)->get(['commission_amount', 'commission_refunded'])->sum(fn (Commission $c) => $c->netCommission()),
            ],
            'upcoming_renewals_7d' => Subscription::where('status', 'active')->where('cancel_at_period_end', false)
                ->whereBetween('current_period_end', [now(), now()->addDays(7)])->count(),
        ];
    }

    /** Monthly recurring revenue: every paying subscription normalised to one month, in minor units. */
    private function mrr(): int
    {
        return Subscription::whereIn('status', ['active', 'past_due', 'grace'])->get(['amount', 'interval', 'interval_count'])
            ->sum(fn (Subscription $s) => self::monthly($s->amount, $s->interval, $s->interval_count));
    }

    /** Integer maths, rounded half up: a year is 12 months, a week 52/12 of a month, a day 30 to the month. */
    public static function monthly(int $amount, string $interval, int $count): int
    {
        [$numerator, $denominator] = match ($interval) {
            'year' => [1, 12 * $count],
            'week' => [52, 12 * $count],
            'day' => [30, $count],
            default => [1, $count],
        };

        return intdiv(2 * $amount * $numerator + $denominator, 2 * $denominator);
    }
}
