import { useState } from 'react';
import { PaymentStatusBadge } from '@/components/billing/StatusBadges';
import { DataTable, type Column } from '@/components/data-table/DataTable';
import { FilterSelect } from '@/components/FilterSelect';
import { PageHeader } from '@/components/PageHeader';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/api';
import { disputeStatusLabels, paymentStatuses, type Payment } from '@/lib/billing';
import { formatDate } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { useListState } from '@/lib/useListState';
import { RefundDialog } from './RefundDialog';
import { usePayments } from './services/billing';

export function PaymentsPage() {
    const list = useListState({ sort: 'created_at', direction: 'desc' });
    const { data, isLoading, error, refetch } = usePayments(list.params);
    const [refunding, setRefunding] = useState<Payment | null>(null);

    const columns: Column<Payment>[] = [
        { id: 'date', header: 'Date', sortKey: 'created_at', cell: (p) => formatDate(p.paid_at ?? p.created_at) },
        {
            id: 'amount',
            header: 'Amount',
            sortKey: 'amount',
            cell: (p) => (
                <>
                    <span className="block">{formatMoney(p.amount, p.currency)}</span>
                    {p.amount_refunded > 0 && (
                        <span className="block text-xs text-muted-foreground">{formatMoney(p.amount_refunded, p.currency)} refunded</span>
                    )}
                </>
            ),
        },
        {
            id: 'fee',
            header: 'Platform fee',
            cell: (p) =>
                p.commission ? (
                    <>
                        <span className="block">{formatMoney(p.commission.net_fee, p.currency)}</span>
                        <span className="block text-xs text-muted-foreground">{p.commission.rate_percent}%</span>
                    </>
                ) : (
                    '—'
                ),
        },
        {
            id: 'net',
            header: 'You receive',
            cell: (p) => (p.status === 'failed' ? '—' : formatMoney(p.commission ? p.commission.provider_net : p.amount - p.amount_refunded, p.currency)),
        },
        {
            id: 'status',
            header: 'Status',
            sortKey: 'status',
            cell: (p) => (
                <>
                    <PaymentStatusBadge status={p.status} />
                    {p.failure_code && <span className="mt-1 block text-xs text-destructive">{p.failure_code}</span>}
                    {p.dispute && (
                        <span className="mt-1 block text-xs text-destructive">
                            {disputeStatusLabels[p.dispute.status] ?? 'Dispute'}
                            {p.dispute.evidence_due_by &&
                                ['needs_response', 'warning_needs_response'].includes(p.dispute.status) &&
                                ` (by ${formatDate(p.dispute.evidence_due_by)})`}
                        </span>
                    )}
                </>
            ),
        },
        {
            id: 'actions',
            header: '',
            className: 'text-right',
            cell: (p) =>
                ['succeeded', 'partially_refunded'].includes(p.status) && p.refundable > 0 ? (
                    <Button variant="outline" size="sm" onClick={() => setRefunding(p)}>
                        Refund {formatMoney(p.refundable, p.currency)}
                    </Button>
                ) : null,
        },
    ];

    return (
        <>
            <PageHeader title="Payments" description="Every charge to your subscribers. Refunds go back to the customer's card." />

            <div className="mb-4 flex flex-wrap items-center gap-4">
                <FilterSelect
                    label="Status"
                    value={list.state.filters.status ?? ''}
                    options={(Object.keys(paymentStatuses) as Payment['status'][]).map((s) => ({ value: s, label: paymentStatuses[s].label }))}
                    allLabel="All statuses"
                    onChange={(value) => list.setFilter('status', value)}
                />
            </div>

            <DataTable
                columns={columns}
                rows={data?.data}
                meta={data?.meta}
                rowKey={(p) => p.id}
                isLoading={isLoading}
                error={error ? (error instanceof ApiError ? error.message : 'Something went wrong.') : undefined}
                onRetry={() => refetch()}
                sort={list.state.sort}
                direction={list.state.direction}
                onSortChange={list.setSort}
                onPageChange={list.setPage}
                emptyTitle="No payments yet"
                emptyHint="Payments appear here after your first subscriber is billed."
            />

            <RefundDialog payment={refunding} onClose={() => setRefunding(null)} />
        </>
    );
}
