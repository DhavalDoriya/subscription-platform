import { Link } from 'react-router-dom';
import { PageHeader } from '@/components/PageHeader';
import { Alert } from '@/components/ui/Alert';
import { Skeleton } from '@/components/ui/skeleton';
import { ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { ProviderStatusBadge } from './ProviderStatusBadge';
import { useDashboard } from './services/dashboard';
import { providerStatuses } from './services/providers';

function Panel({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <section aria-label={title} className="rounded-lg border bg-card p-5">
            <h2 className="text-sm font-medium text-muted-foreground">{title}</h2>
            {children}
        </section>
    );
}

/** Amounts per currency, e.g. "$80.00 · €15.00"; they cannot be added across currencies. */
const money = (byCurrency: Record<string, number>) => {
    const parts = Object.entries(byCurrency).map(([currency, amount]) => formatMoney(amount, currency));
    return parts.length ? parts.join(' · ') : formatMoney(0, 'usd');
};

export function DashboardPage() {
    const { data, error, isLoading } = useDashboard();

    return (
        <>
            <PageHeader title="Dashboard" description="How the platform looks right now." />

            {error && <Alert>{error instanceof ApiError ? error.message : 'Something went wrong.'}</Alert>}
            {isLoading && <Skeleton className="h-40 w-full" />}

            {data && (
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="lg:col-span-2">
                        <Panel title="Providers">
                            <p className="mt-1 text-4xl font-semibold tabular-nums">{data.providers.total}</p>
                            <ul className="mt-4 grid grid-cols-2 gap-x-8 gap-y-2 sm:grid-cols-3">
                                {providerStatuses.map((s) => (
                                    <li key={s.value} className="flex items-center justify-between text-sm">
                                        <span>{s.label}</span>
                                        <span className="font-medium tabular-nums">{data.providers.by_status[s.value]}</span>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    </div>
                    <div className="lg:col-span-3">
                        <Panel title="Payment recovery">
                            <dl className="mt-3 grid grid-cols-2 gap-4 lg:grid-cols-4">
                                {(
                                    [
                                        ['Failed invoices', String(data.dunning.open_invoices)],
                                        ['Amount outstanding', money(data.dunning.open_amount)],
                                        ['Monthly revenue at risk', money(data.dunning.at_risk_mrr)],
                                        ['Recovery rate (30 days)', data.dunning.recovery_rate === null ? '—' : `${data.dunning.recovery_rate}%`],
                                    ] as const
                                ).map(([label, value]) => (
                                    <div key={label}>
                                        <dt className="text-sm text-muted-foreground">{label}</dt>
                                        <dd className="mt-1 text-2xl font-semibold tabular-nums">{value}</dd>
                                    </div>
                                ))}
                            </dl>
                        </Panel>
                    </div>
                    <Panel title="Customers">
                        <p className="mt-1 text-4xl font-semibold tabular-nums">{data.customers.total}</p>
                        <Link to="/admin/customers" className="mt-4 inline-block text-sm text-primary hover:underline">
                            View customers
                        </Link>
                    </Panel>

                    <div className="lg:col-span-2">
                        <Panel title="Recent providers">
                            <ul className="mt-3 divide-y">
                                {data.recent_providers.length === 0 && <li className="py-2 text-sm text-muted-foreground">No providers yet.</li>}
                                {data.recent_providers.map((p) => (
                                    <li key={p.id} className="flex items-center justify-between gap-4 py-2 text-sm">
                                        <span className="font-medium">{p.name}</span>
                                        <span className="flex items-center gap-3">
                                            <ProviderStatusBadge status={p.status} />
                                            <span className="text-muted-foreground">{formatDate(p.created_at)}</span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    </div>
                    <Panel title="Recent customers">
                        <ul className="mt-3 divide-y">
                            {data.recent_customers.length === 0 && <li className="py-2 text-sm text-muted-foreground">No customers yet.</li>}
                            {data.recent_customers.map((c) => (
                                <li key={c.id} className="py-2 text-sm">
                                    <span className="block font-medium">{c.name}</span>
                                    <span className="block text-muted-foreground">{c.email}</span>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                </div>
            )}
        </>
    );
}
