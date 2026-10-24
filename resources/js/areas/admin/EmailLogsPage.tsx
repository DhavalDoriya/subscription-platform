import { Eye, RotateCw } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { DataTable, type Column } from '@/components/data-table/DataTable';
import { FilterSelect } from '@/components/FilterSelect';
import { PageHeader } from '@/components/PageHeader';
import { SearchInput } from '@/components/SearchInput';
import { StatusBadge } from '@/components/StatusBadge';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ApiError } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { useListState } from '@/lib/useListState';
import { emailStatusLabels, useEmailLog, useEmailLogs, useResendEmail, type EmailLog, type EmailStatus } from './services/email';

const tones: Record<EmailStatus, 'good' | 'bad' | 'warn' | 'neutral'> = { sent: 'good', failed: 'bad', queued: 'warn', skipped: 'neutral' };
const message = (e: unknown) => (e instanceof ApiError ? e.message : 'Something went wrong.');

function EmailDetail({ id, onClose }: { id: string | null; onClose: () => void }) {
    const { data, isLoading, error } = useEmailLog(id);

    return (
        <Dialog open={id !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{data?.subject ?? 'Email'}</DialogTitle>
                    <DialogDescription>{data ? `To ${data.to_email}` : ''}</DialogDescription>
                </DialogHeader>
                {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}
                {error && <Alert>{message(error)}</Alert>}
                {data?.error && <Alert>{data.error}</Alert>}
                {data && <iframe title="Sent email" sandbox="" srcDoc={data.html_body ?? ''} className="h-80 w-full rounded-md border bg-white" />}
            </DialogContent>
        </Dialog>
    );
}

export function EmailLogsPage() {
    const list = useListState({ sort: 'created_at', direction: 'desc' });
    const { data, isLoading, error, refetch } = useEmailLogs(list.params);
    const resend = useResendEmail();
    const [viewing, setViewing] = useState<string | null>(null);
    const [actionError, setActionError] = useState<string>();

    async function retry(log: EmailLog) {
        setActionError(undefined);
        try {
            const result = await resend.mutateAsync(log.id);
            if (result.status === 'sent') toast.success(`Email to ${log.to_email} sent`);
            else setActionError(result.error ?? 'The email could not be sent.');
        } catch (e) {
            setActionError(message(e));
        }
    }

    const columns: Column<EmailLog>[] = [
        { id: 'to', header: 'To', sortKey: 'to_email', cell: (l) => <span className="font-medium">{l.to_email}</span> },
        {
            id: 'email',
            header: 'Email',
            cell: (l) => (
                <>
                    <span className="block text-sm">{l.subject}</span>
                    <span className="block text-xs text-muted-foreground">{l.template_key}</span>
                </>
            ),
        },
        { id: 'status', header: 'Status', sortKey: 'status', cell: (l) => <StatusBadge label={emailStatusLabels[l.status]} tone={tones[l.status]} /> },
        { id: 'error', header: 'Error', cell: (l) => (l.error ? <span className="text-xs text-destructive">{l.error}</span> : '—') },
        { id: 'created', header: 'Queued', sortKey: 'created_at', cell: (l) => formatDateTime(l.created_at), className: 'whitespace-nowrap' },
        {
            id: 'actions',
            header: '',
            className: 'text-right',
            cell: (l) => (
                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" aria-label={`View email to ${l.to_email}`} onClick={() => setViewing(l.id)}>
                        <Eye aria-hidden /> View
                    </Button>
                    {l.status === 'failed' && (
                        <Button variant="outline" size="sm" aria-label={`Resend email to ${l.to_email}`} disabled={resend.isPending} onClick={() => retry(l)}>
                            <RotateCw aria-hidden /> Resend
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <>
            <PageHeader title="Email log" description="Every email the platform tried to send. Failed emails can be resent." />

            {actionError && (
                <div className="mb-4">
                    <Alert>{actionError}</Alert>
                </div>
            )}

            <div className="mb-4 flex flex-wrap items-center gap-4">
                <SearchInput label="Search emails" onSearch={list.setSearch} />
                <FilterSelect
                    label="Status"
                    value={list.state.filters.status ?? ''}
                    options={(Object.keys(emailStatusLabels) as EmailStatus[]).map((s) => ({ value: s, label: emailStatusLabels[s] }))}
                    allLabel="All statuses"
                    onChange={(value) => list.setFilter('status', value)}
                />
            </div>

            <DataTable
                columns={columns}
                rows={data?.data}
                meta={data?.meta}
                rowKey={(l) => l.id}
                isLoading={isLoading}
                error={error ? message(error) : undefined}
                onRetry={() => refetch()}
                sort={list.state.sort}
                direction={list.state.direction}
                onSortChange={list.setSort}
                onPageChange={list.setPage}
                emptyTitle="No emails yet"
                emptyHint="Emails appear here as soon as the platform sends one."
            />

            <EmailDetail id={viewing} onClose={() => setViewing(null)} />
        </>
    );
}
