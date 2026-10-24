import { ExternalLink } from 'lucide-react';
import { InvoiceRecoveryActions, RecoveryCell } from '@/components/billing/Recovery';
import { InvoiceStatusBadge } from '@/components/billing/StatusBadges';
import { DataTable, type Column } from '@/components/data-table/DataTable';
import { FilterSelect } from '@/components/FilterSelect';
import { PageHeader } from '@/components/PageHeader';
import { SearchInput } from '@/components/SearchInput';
import { ApiError } from '@/lib/api';
import { invoiceStatuses, type Invoice } from '@/lib/billing';
import { formatDate } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { useListState } from '@/lib/useListState';
import { useAdminInvoices } from './services/billing';

export function InvoicesPage() {
    const list = useListState({ sort: 'created_at', direction: 'desc' });
    const { data, isLoading, error, refetch } = useAdminInvoices(list.params);

    const columns: Column<Invoice>[] = [
        { id: 'number', header: 'Invoice', cell: (i) => <span className="font-medium">{i.number ?? '—'}</span> },
        {
            id: 'customer',
            header: 'Customer',
            cell: (i) => (
                <>
                    <span className="block">{i.customer?.name}</span>
                    <span className="block text-xs text-muted-foreground">{i.customer?.email}</span>
                </>
            ),
        },
        { id: 'provider', header: 'Provider', cell: (i) => i.provider?.name },
        { id: 'amount', header: 'Amount', sortKey: 'amount_due', cell: (i) => formatMoney(i.amount_due, i.currency) },
        { id: 'status', header: 'Status', sortKey: 'status', cell: (i) => <InvoiceStatusBadge status={i.status} /> },
        { id: 'created', header: 'Date', sortKey: 'created_at', cell: (i) => formatDate(i.created_at) },
        { id: 'recovery', header: 'Recovery', cell: (i) => <RecoveryCell invoice={i} /> },
        {
            id: 'link',
            header: '',
            className: 'text-right',
            cell: (i) => (
                <div className="flex items-center justify-end gap-1">
                    <InvoiceRecoveryActions invoice={i} basePath="/admin" />
                    {i.hosted_invoice_url ? (
                        <a
                            href={i.hosted_invoice_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label={`Open invoice ${i.number}`}
                            className="inline-flex text-primary hover:underline"
                        >
                            <ExternalLink aria-hidden className="size-4" />
                        </a>
                    ) : null}
                </div>
            ),
        },
    ];

    return (
        <>
            <PageHeader title="Invoices" description="Every invoice issued on the platform, mirrored from Stripe." />

            <div className="mb-4 flex flex-wrap items-center gap-4">
                <SearchInput label="Search invoices" onSearch={list.setSearch} />
                <FilterSelect
                    label="Status"
                    value={list.state.filters.status ?? ''}
                    options={(Object.keys(invoiceStatuses) as Invoice['status'][]).map((s) => ({ value: s, label: invoiceStatuses[s].label }))}
                    allLabel="All statuses"
                    onChange={(value) => list.setFilter('status', value)}
                />
                <FilterSelect
                    label="Recovery"
                    value={list.state.filters.dunning ?? ''}
                    options={[{ value: 'active', label: 'Needs attention' }]}
                    allLabel="All invoices"
                    onChange={(value) => list.setFilter('dunning', value)}
                />
            </div>

            <DataTable
                columns={columns}
                rows={data?.data}
                meta={data?.meta}
                rowKey={(i) => i.id}
                isLoading={isLoading}
                error={error ? (error instanceof ApiError ? error.message : 'Something went wrong.') : undefined}
                onRetry={() => refetch()}
                sort={list.state.sort}
                direction={list.state.direction}
                onSortChange={list.setSort}
                onPageChange={list.setPage}
                emptyTitle="No invoices yet"
                emptyHint="Invoices appear here after the first billing cycle."
            />
        </>
    );
}
