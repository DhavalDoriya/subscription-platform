import { Eye } from 'lucide-react';
import { useState } from 'react';
import { SubscriptionStatusBadge } from '@/components/billing/StatusBadges';
import { DataTable, type Column } from '@/components/data-table/DataTable';
import { FilterSelect } from '@/components/FilterSelect';
import { PageHeader } from '@/components/PageHeader';
import { SearchInput } from '@/components/SearchInput';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/api';
import { subscriptionStatuses, type Subscription, type SubscriptionStatus } from '@/lib/billing';
import { formatDate } from '@/lib/format';
import { formatPrice } from '@/lib/money';
import { useListState } from '@/lib/useListState';
import { useSubscriptions } from './services/billing';
import { SubscriptionDetailDialog } from './SubscriptionDetailDialog';

export function SubscribersPage() {
    const list = useListState({ sort: 'created_at', direction: 'desc' });
    const { data, isLoading, error, refetch } = useSubscriptions(list.params);
    const [selected, setSelected] = useState<string | null>(null);

    const columns: Column<Subscription>[] = [
        {
            id: 'customer',
            header: 'Subscriber',
            cell: (s) => (
                <>
                    <span className="block font-medium">{s.customer?.name}</span>
                    <span className="block text-xs text-muted-foreground">{s.customer?.email}</span>
                </>
            ),
        },
        { id: 'plan', header: 'Plan', cell: (s) => s.plan?.name },
        { id: 'price', header: 'Price', sortKey: 'amount', cell: (s) => formatPrice(s) },
        { id: 'status', header: 'Status', sortKey: 'status', cell: (s) => <SubscriptionStatusBadge subscription={s} /> },
        { id: 'renews', header: 'Renews / ends', sortKey: 'current_period_end', cell: (s) => formatDate(s.current_period_end) },
        { id: 'since', header: 'Since', sortKey: 'created_at', cell: (s) => formatDate(s.created_at) },
        {
            id: 'actions',
            header: '',
            className: 'text-right',
            cell: (s) => (
                <Button variant="outline" size="sm" aria-label={`View ${s.customer?.name}`} onClick={() => setSelected(s.id)}>
                    <Eye aria-hidden /> View
                </Button>
            ),
        },
    ];

    return (
        <>
            <PageHeader title="Subscribers" description="Customers who subscribed to your plans. You only ever see people who actually joined." />

            <div className="mb-4 flex flex-wrap items-center gap-4">
                <SearchInput label="Search subscribers" onSearch={list.setSearch} />
                <FilterSelect
                    label="Status"
                    value={list.state.filters.status ?? ''}
                    options={(Object.keys(subscriptionStatuses) as SubscriptionStatus[]).map((s) => ({ value: s, label: subscriptionStatuses[s].label }))}
                    allLabel="All statuses"
                    onChange={(value) => list.setFilter('status', value)}
                />
            </div>

            <DataTable
                columns={columns}
                rows={data?.data}
                meta={data?.meta}
                rowKey={(s) => s.id}
                isLoading={isLoading}
                error={error ? (error instanceof ApiError ? error.message : 'Something went wrong.') : undefined}
                onRetry={() => refetch()}
                sort={list.state.sort}
                direction={list.state.direction}
                onSortChange={list.setSort}
                onPageChange={list.setPage}
                emptyTitle="No subscribers yet"
                emptyHint="Share your storefront link. Subscribers show up here as soon as they join."
            />

            <SubscriptionDetailDialog subscriptionId={selected} onClose={() => setSelected(null)} />
        </>
    );
}
