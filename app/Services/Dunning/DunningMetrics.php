<?php

namespace App\Services\Dunning;

use App\Models\DunningEvent;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Billing\BillingMetrics;

/**
 * Failed payments, recovery rate and revenue at risk (FR-R12). Reads whatever tenant scope is active, so a provider
 * sees its own numbers and the admin (no tenant) sees the platform's.
 */
class DunningMetrics
{
    /** @return array{open_invoices: int, open_amount: int, at_risk_mrr: int, recovered_30d: int, lost_30d: int, recovery_rate: ?int} */
    public function forProvider(): array
    {
        $open = $this->openInvoices();

        return [
            'open_invoices' => $open->count(),
            'open_amount' => (int) $open->sum('amount_due'),
            'at_risk_mrr' => $this->atRisk()->sum(fn (Subscription $s) => BillingMetrics::monthly($s->amount, $s->interval, $s->interval_count)),
            ...$this->recovery(),
        ];
    }

    /** Same numbers across every provider; money is split by currency because they cannot be added together. */
    public function forPlatform(): array
    {
        $open = $this->openInvoices();

        return [
            'open_invoices' => $open->count(),
            'open_amount' => $open->groupBy('currency')->map(fn ($invoices) => (int) $invoices->sum('amount_due'))->all(),
            'at_risk_mrr' => $this->atRisk()->groupBy('currency')
                ->map(fn ($subs) => (int) $subs->sum(fn (Subscription $s) => BillingMetrics::monthly($s->amount, $s->interval, $s->interval_count)))->all(),
            ...$this->recovery(),
        ];
    }

    private function openInvoices()
    {
        return Invoice::where('status', 'open')->whereIn('dunning_status', DunningService::ACTIVE)->get(['id', 'amount_due', 'currency']);
    }

    private function atRisk()
    {
        return Subscription::whereIn('status', ['past_due', 'grace'])->get(['amount', 'currency', 'interval', 'interval_count']);
    }

    /** @return array{recovered_30d: int, lost_30d: int, recovery_rate: ?int} */
    private function recovery(): array
    {
        $since = now()->subDays(30);
        $recovered = DunningEvent::where('type', 'recovered')->where('occurred_at', '>=', $since)->count();
        $lost = DunningEvent::whereIn('type', ['canceled', 'paused'])->where('occurred_at', '>=', $since)->count();

        return [
            'recovered_30d' => $recovered,
            'lost_30d' => $lost,
            'recovery_rate' => $recovered + $lost > 0 ? (int) round($recovered * 100 / ($recovered + $lost)) : null,
        ];
    }
}
