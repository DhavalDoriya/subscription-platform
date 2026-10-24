import { Archive } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { DataTable, type Column } from '@/components/data-table/DataTable';
import { FilterSelect } from '@/components/FilterSelect';
import { PageHeader } from '@/components/PageHeader';
import { SearchInput } from '@/components/SearchInput';
import { StatusBadge } from '@/components/StatusBadge';
import { Button } from '@/components/ui/button';
import { planStatusLabels, type PlanStatus } from '@/areas/provider/services/plans';
import { ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { formatPrice } from '@/lib/money';
import { useListState } from '@/lib/useListState';
import { useAdminPlans, useForceArchive, type AdminPlan } from './services/adminPlans';

const tones: Record<PlanStatus, 'neutral' | 'good' | 'warn'> = { draft: 'warn', active: 'good', archived: 'neutral' };

export function PlansAdminPage() {
    const list = useListState({ sort: 'created_at', direction: 'desc' });
    const { data, isLoading, error, refetch } = useAdminPlans(list.params);
    const archive = useForceArchive();
    const [archiving, setArchiving] = useState<AdminPlan | null>(null);

    const columns: Column<AdminPlan>[] = [
        { id: 'name', header: 'Plan', sortKey: 'name', cell: (p) => <span className="font-medium">{p.name}</span> },
        {
            id: 'provider',
            header: 'Provider',
            cell: (p) => (
                <>
                    <span className="block">{p.provider.name}</span>
                    <span className="block text-xs text-muted-foreground">{p.provider.slug}</span>
                </>
            ),
        },
        { id: 'status', header: 'Status', sortKey: 'status', cell: (p) => <StatusBadge label={planStatusLabels[p.status]} tone={tones[p.status]} /> },
        {
            id: 'prices',
            header: 'Prices',
            cell: (p) =>
                p.prices.length
                    ? p.prices.map((price) => (
                          <span key={price.id} className="block text-sm">
                              {formatPrice(price)}
                          </span>
                      ))
                    : '—',
        },
        { id: 'created', header: 'Created', sortKey: 'created_at', cell: (p) => formatDate(p.created_at) },
        {
            id: 'actions',
            header: '',
            className: 'text-right',
            cell: (p) =>
                p.status === 'archived' ? null : (
                    <Button variant="outline" size="sm" aria-label={`Archive ${p.name}`} onClick={() => setArchiving(p)}>
                        <Archive aria-hidden /> Archive
                    </Button>
                ),
        },
    ];

    return (
        <>
            <PageHeader title="Plans" description="Every provider's plans. Archiving stops new sign-ups; existing subscribers keep their plan." />

            <div className="mb-4 flex flex-wrap items-center gap-4">
                <SearchInput label="Search plans" onSearch={list.setSearch} />
                <FilterSelect
                    label="Status"
                    value={list.state.filters.status ?? ''}
                    options={(Object.keys(planStatusLabels) as PlanStatus[]).map((s) => ({ value: s, label: planStatusLabels[s] }))}
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
                emptyTitle="No plans found"
                emptyHint="Plans appear here once providers create them."
            />

            <ConfirmDialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
                title={`Archive ${archiving?.name ?? ''}?`}
                description={`This hides ${archiving?.name ?? 'the plan'} from new sign-ups on ${archiving?.provider.name ?? 'the provider'}'s storefront and on Stripe. Existing subscribers keep it. The action is recorded in the audit log.`}
                confirmLabel="Archive"
                destructive
                onConfirm={async () => {
                    await archive.mutateAsync(archiving!.id);
                    toast.success(`${archiving!.name} archived`);
                }}
            />
        </>
    );
}
