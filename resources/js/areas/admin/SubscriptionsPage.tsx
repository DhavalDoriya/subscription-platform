import { Ban } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { SubscriptionStatusBadge } from '@/components/billing/StatusBadges';
import { ConfirmDialog } from '@/components/ConfirmDialog';
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
import { useAdminCancelSubscription, useAdminSubscriptions } from './services/billing';

export function SubscriptionsPage() {
    const list = useListState({ sort: 'created_at', direction: 'desc' });
    const { data, isLoading, error, refetch } = useAdminSubscriptions(list.params);
    const cancel = useAdminCancelSubscription();
    const [canceling, setCanceling] = useState<Subscription | null>(null);

    const columns: Column<Subscription>[] = [
        {
            id: 'customer',
            header: 'Customer',
            cell: (s) => (
                <>
                    <span className="block font-medium">{s.customer?.name}</span>
                    <span className="block text-xs text-muted-foreground">{s.customer?.email}</span>
                </>
            ),
        },
        { id: 'provider', header: 'Provider', cell: (s) => s.provider?.name },
        { id: 'plan', header: 'Plan', cell: (s) => s.plan?.name },
        { id: 'price', header: 'Price', sortKey: 'amount', cell: (s) => formatPrice(s) },
        { id: 'status', header: 'Status', sortKey: 'status', cell: (s) => <SubscriptionStatusBadge subscription={s} /> },
        { id: 'renews', header: 'Renews / ends', sortKey: 'current_period_end', cell: (s) => formatDate(s.current_period_end) },
        {
            id: 'actions',
            header: '',
            className: 'text-right',
            cell: (s) =>
                ['canceled', 'expired', 'incomplete'].includes(s.status) ? null : (
                    <Button variant="outline" size="sm" aria-label={`Cancel ${s.customer?.name}'s subscription`} onClick={() => setCanceling(s)}>
                        <Ban aria-hidden /> Cancel
                    </Button>
                ),
        },
    ];

    return (
        <>
            <PageHeader title="Subscriptions" description="Every subscription on the platform. Manual actions are recorded in the audit log." />

            <div className="mb-4 flex flex-wrap items-center gap-4">
                <SearchInput label="Search subscriptions" onSearch={list.setSearch} />
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
                emptyTitle="No subscriptions found"
                emptyHint="Subscriptions appear here once customers join a provider."
            />

            <ConfirmDialog
                open={canceling !== null}
                onOpenChange={(open) => !open && setCanceling(null)}
                title={`Cancel ${canceling?.customer?.name ?? 'this'} subscription?`}
                description={`${canceling?.customer?.name ?? 'The customer'} loses access to ${canceling?.plan?.name ?? 'the plan'} from ${canceling?.provider?.name ?? 'the provider'} immediately. Money already paid is not refunded. The action is recorded in the audit log.`}
                confirmLabel="Cancel subscription"
                destructive
                onConfirm={async () => {
                    await cancel.mutateAsync({ id: canceling!.id, when: 'now' });
                    toast.success('Subscription canceled');
                }}
            />
        </>
    );
}
