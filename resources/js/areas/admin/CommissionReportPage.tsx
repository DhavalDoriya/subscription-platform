import { useState } from 'react';
import { DataTable, type Column } from '@/components/data-table/DataTable';
import { FilterSelect } from '@/components/FilterSelect';
import { PageHeader } from '@/components/PageHeader';
import { Skeleton } from '@/components/ui/skeleton';
import { ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { useListState } from '@/lib/useListState';
import { useCommissionReport, type CommissionRow } from './services/commission';
import { useProviders } from './services/providers';

/** FR-CM4: what the platform earned per payment, with totals per currency for the filtered range. */
export function CommissionReportPage() {
    const list = useListState({ sort: 'created_at', direction: 'desc' });
    const { data, isLoading, error, refetch } = useCommissionReport(list.params);
    const providers = useProviders({ per_page: 100, sort: 'name', direction: 'asc' });
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');

    const columns: Column<CommissionRow>[] = [
        { id: 'date', header: 'Date', sortKey: 'created_at', cell: (r) => formatDate(r.created_at), className: 'whitespace-nowrap' },
        { id: 'provider', header: 'Provider', cell: (r) => <span className="font-medium">{r.provider?.name ?? '—'}</span> },
        {
            id: 'gross',
            header: 'Payment',
            sortKey: 'gross_amount',
            cell: (r) => (
                <>
                    <span className="block">{formatMoney(r.gross_amount, r.currency)}</span>
                    {r.refunded_amount > 0 && (
                        <span className="block text-xs text-muted-foreground">{formatMoney(r.refunded_amount, r.currency)} refunded</span>
                    )}
                </>
            ),
        },
        { id: 'rate', header: 'Rate', cell: (r) => `${r.rate_percent}%` },
        {
            id: 'fee',
            header: 'Platform fee',
            sortKey: 'commission_amount',
            cell: (r) => (
                <>
                    <span className="block">{formatMoney(r.net_commission, r.currency)}</span>
                    {r.commission_refunded > 0 && (
                        <span className="block text-xs text-muted-foreground">{formatMoney(r.commission_refunded, r.currency)} given back</span>
                    )}
                </>
            ),
        },
        { id: 'net', header: 'Provider receives', cell: (r) => formatMoney(r.provider_net, r.currency) },
    ];

    return (
        <>
            <PageHeader title="Commission report" description="The platform fee on every payment, after refunds." />

            <div className="mb-4 flex flex-wrap items-center gap-4">
                <FilterSelect
                    label="Provider"
                    value={list.state.filters.provider_id ?? ''}
                    options={(providers.data?.data ?? []).map((p) => ({ value: p.id, label: p.name }))}
                    allLabel="All providers"
                    onChange={(value) => list.setFilter('provider_id', value)}
                />
                <label className="flex items-center gap-2 text-sm">
                    <span className="text-muted-foreground">From</span>
                    <input
                        type="date"
                        value={from}
                        onChange={(e) => {
                            setFrom(e.target.value);
                            list.setFilter('from', e.target.value);
                        }}
                        className="h-8 rounded-lg border border-input bg-card px-2.5 text-sm"
                    />
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <span className="text-muted-foreground">To</span>
                    <input
                        type="date"
                        value={to}
                        onChange={(e) => {
                            setTo(e.target.value);
                            list.setFilter('to', e.target.value);
                        }}
                        className="h-8 rounded-lg border border-input bg-card px-2.5 text-sm"
                    />
                </label>
            </div>

            {isLoading && <Skeleton className="mb-6 h-20 w-full" />}
            {data?.totals.map((t) => (
                <section key={t.currency} aria-label={`Totals in ${t.currency.toUpperCase()}`} className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                    {(
                        [
                            ['Payments', formatMoney(t.gross, t.currency)],
                            ['Refunded', formatMoney(t.refunded, t.currency)],
                            ['Commission earned', formatMoney(t.commission, t.currency)],
                            ['Commission kept', formatMoney(t.net_commission, t.currency)],
                        ] as const
                    ).map(([title, value]) => (
                        <div key={title} className="rounded-lg border bg-card p-4">
                            <p className="text-sm text-muted-foreground">
                                {title} ({t.currency.toUpperCase()})
                            </p>
                            <p className="mt-1 text-2xl font-semibold">{value}</p>
                        </div>
                    ))}
                </section>
            ))}

            <DataTable
                columns={columns}
                rows={data?.data}
                meta={data?.meta}
                rowKey={(r) => r.id}
                isLoading={isLoading}
                error={error ? (error instanceof ApiError ? error.message : 'Something went wrong.') : undefined}
                onRetry={() => refetch()}
                sort={list.state.sort}
                direction={list.state.direction}
                onSortChange={list.setSort}
                onPageChange={list.setPage}
                emptyTitle="No commission yet"
                emptyHint="Commission appears here as payments are made at a rate above zero."
            />
        </>
    );
}
