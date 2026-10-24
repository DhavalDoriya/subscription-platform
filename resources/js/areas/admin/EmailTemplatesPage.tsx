import { Pencil } from 'lucide-react';
import { Link } from 'react-router-dom';
import { DataTable, type Column } from '@/components/data-table/DataTable';
import { PageHeader } from '@/components/PageHeader';
import { SearchInput } from '@/components/SearchInput';
import { StatusBadge } from '@/components/StatusBadge';
import { buttonVariants } from '@/components/ui/button';
import { ApiError } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { useListState } from '@/lib/useListState';
import { useEmailTemplates, type EmailTemplate } from './services/email';

export function EmailTemplatesPage() {
    const list = useListState({ sort: 'key', direction: 'asc' });
    const { data, isLoading, error, refetch } = useEmailTemplates(list.params);

    const columns: Column<EmailTemplate>[] = [
        {
            id: 'template',
            header: 'Template',
            sortKey: 'key',
            cell: (t) => (
                <>
                    <span className="block font-medium">{t.name}</span>
                    <span className="block text-xs text-muted-foreground">{t.key}</span>
                </>
            ),
        },
        { id: 'subject', header: 'Subject', cell: (t) => <span className="text-sm">{t.subject}</span> },
        {
            id: 'status',
            header: 'Status',
            sortKey: 'enabled',
            cell: (t) => <StatusBadge label={t.enabled ? 'Enabled' : 'Disabled'} tone={t.enabled ? 'good' : 'neutral'} />,
        },
        { id: 'version', header: 'Version', cell: (t) => <span>{`v${t.version}`}</span>, className: 'tabular-nums' },
        { id: 'updated', header: 'Updated', sortKey: 'updated_at', cell: (t) => formatDateTime(t.updated_at), className: 'whitespace-nowrap' },
        {
            id: 'actions',
            header: '',
            className: 'text-right',
            cell: (t) => (
                <Link to={`/admin/email-templates/${t.id}`} aria-label={`Edit ${t.key}`} className={buttonVariants({ variant: 'outline', size: 'sm' })}>
                    <Pencil aria-hidden /> Edit
                </Link>
            ),
        },
    ];

    return (
        <>
            <PageHeader title="Email templates" description="Every email the platform sends. Edit the wording; the next email uses it." />

            <div className="mb-4 flex flex-wrap items-center gap-4">
                <SearchInput label="Search templates" onSearch={list.setSearch} />
            </div>

            <DataTable
                columns={columns}
                rows={data?.data}
                meta={data?.meta}
                rowKey={(t) => t.id}
                isLoading={isLoading}
                error={error ? (error instanceof ApiError ? error.message : 'Something went wrong.') : undefined}
                onRetry={() => refetch()}
                sort={list.state.sort}
                direction={list.state.direction}
                onSortChange={list.setSort}
                onPageChange={list.setPage}
                emptyTitle="No templates found"
            />
        </>
    );
}
