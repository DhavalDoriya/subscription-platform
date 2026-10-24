import { DataTable, type Column } from '@/components/data-table/DataTable';
import { PageHeader } from '@/components/PageHeader';
import { SearchInput } from '@/components/SearchInput';
import { ApiError } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { useListState } from '@/lib/useListState';
import { summarize, useAuditLogs, type AuditEntry } from './services/audit';

export function AuditLogPage() {
    const list = useListState({ sort: 'created_at', direction: 'desc' });
    const { data, isLoading, error, refetch } = useAuditLogs(list.params);

    const columns: Column<AuditEntry>[] = [
        { id: 'when', header: 'When', sortKey: 'created_at', cell: (e) => formatDateTime(e.created_at), className: 'whitespace-nowrap' },
        { id: 'event', header: 'Event', cell: (e) => <code className="text-xs">{e.description}</code> },
        { id: 'actor', header: 'Actor', cell: (e) => e.causer?.name ?? 'System' },
        {
            id: 'subject',
            header: 'Subject',
            cell: (e) => (e.subject_type ? <span className="text-muted-foreground">{e.subject_type.split('\\').pop()}</span> : '—'),
        },
        {
            id: 'details',
            header: 'Details',
            cell: (e) =>
                summarize(e).map((line) => (
                    <span key={line} className="block text-xs text-muted-foreground">
                        {line}
                    </span>
                )),
        },
    ];

    return (
        <>
            <PageHeader title="Audit log" description="Every change made by admins and providers. Entries cannot be edited or removed." />

            <div className="mb-4">
                <SearchInput label="Search events" onSearch={list.setSearch} />
            </div>

            <DataTable
                columns={columns}
                rows={data?.data}
                meta={data?.meta}
                rowKey={(e) => e.id}
                isLoading={isLoading}
                error={error ? (error instanceof ApiError ? error.message : 'Something went wrong.') : undefined}
                onRetry={() => refetch()}
                sort={list.state.sort}
                direction={list.state.direction}
                onSortChange={list.setSort}
                onPageChange={list.setPage}
                emptyTitle="No entries yet"
                emptyHint="Actions like approving a provider or changing a setting appear here."
            />
        </>
    );
}
