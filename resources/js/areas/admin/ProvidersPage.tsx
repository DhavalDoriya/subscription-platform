import { useState } from 'react';
import { DataTable, type Column } from '@/components/data-table/DataTable';
import { FilterSelect } from '@/components/FilterSelect';
import { PageHeader } from '@/components/PageHeader';
import { SearchInput } from '@/components/SearchInput';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { useListState } from '@/lib/useListState';
import { ProviderDialog } from './ProviderDialog';
import { ProviderStatusBadge } from './ProviderStatusBadge';
import { providerStatuses, useProviders, type AdminProvider } from './services/providers';

export function ProvidersPage() {
    const list = useListState({ sort: 'created_at', direction: 'desc' });
    const { data, isLoading, error, refetch } = useProviders(list.params);
    const [selectedId, setSelectedId] = useState<string | null>(null);

    const columns: Column<AdminProvider>[] = [
        {
            id: 'name',
            header: 'Provider',
            sortKey: 'name',
            cell: (p) => (
                <>
                    <span className="block font-medium">{p.name}</span>
                    <span className="block text-xs text-muted-foreground">{p.slug}</span>
                </>
            ),
        },
        { id: 'status', header: 'Status', sortKey: 'status', cell: (p) => <ProviderStatusBadge status={p.status} /> },
        { id: 'customers', header: 'Customers', cell: (p) => p.customers_count, className: 'tabular-nums' },
        { id: 'created', header: 'Registered', sortKey: 'created_at', cell: (p) => formatDate(p.created_at) },
        {
            id: 'actions',
            header: '',
            className: 'text-right',
            cell: (p) => (
                <Button variant="outline" size="sm" aria-label={`View ${p.name}`} onClick={() => setSelectedId(p.id)}>
                    View
                </Button>
            ),
        },
    ];

    return (
        <>
            <PageHeader title="Providers" description="Brands selling subscriptions on the platform." />

            <div className="mb-4 flex flex-wrap items-center gap-4">
                <SearchInput label="Search providers" onSearch={list.setSearch} />
                <FilterSelect
                    label="Status"
                    value={list.state.filters.status ?? ''}
                    options={providerStatuses}
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
                emptyTitle="No providers found"
                emptyHint="Try a different search or status."
            />

            <ProviderDialog provider={data?.data.find((p) => p.id === selectedId) ?? null} onClose={() => setSelectedId(null)} />
        </>
    );
}
