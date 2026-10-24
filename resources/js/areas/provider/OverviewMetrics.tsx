import { Alert } from '@/components/ui/Alert';
import { Skeleton } from '@/components/ui/skeleton';
import { ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { useOverview } from './services/billing';

function Tiles({ label, tiles }: { label: string; tiles: [string, string][] }) {
    return (
        <section aria-label={label} className="mb-8 grid grid-cols-2 gap-4 lg:grid-cols-3">
            {tiles.map(([title, value]) => (
                <div key={title} className="rounded-lg border bg-card p-4">
                    <p className="text-sm text-muted-foreground">{title}</p>
                    <p className="mt-1 text-2xl font-semibold">{value}</p>
                </div>
            ))}
        </section>
    );
}

/** The numbers a provider checks first (FR-PD1), then how well failed payments are being recovered (FR-R12). */
export function OverviewMetrics() {
    const { data, error } = useOverview();

    if (error) return <Alert>{error instanceof ApiError ? error.message : 'Something went wrong.'}</Alert>;
    if (!data) return <Skeleton className="h-24 w-full" />;

    const dunning = data.dunning;
    const recovery: [string, string][] = [
        ['Failed invoices', String(dunning.open_invoices)],
        ['Amount outstanding', formatMoney(dunning.open_amount, data.currency)],
        ['Monthly revenue at risk', formatMoney(dunning.at_risk_mrr, data.currency)],
        ['Recovery rate (30 days)', dunning.recovery_rate === null ? '—' : `${dunning.recovery_rate}%`],
    ];
    const tiles: [string, string][] = [
        ['Monthly recurring revenue', formatMoney(data.mrr, data.currency)],
        ['Active subscribers', String(data.active_subscribers)],
        ['New in 30 days', String(data.new_30d)],
        ['Churned in 30 days', String(data.churned_30d)],
        ['Failed payments (30 days)', String(data.failed_payments_30d)],
        ['Renewals in 7 days', String(data.upcoming_renewals_7d)],
    ];

    return (
        <>
            <Tiles label="Key numbers" tiles={tiles} />
            <Tiles label="Payment recovery" tiles={recovery} />
        </>
    );
}
