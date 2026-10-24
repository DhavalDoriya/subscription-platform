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
import { useReplayEvent, useWebhookEvent, useWebhookEvents, webhookStatusLabels, type WebhookEvent, type WebhookStatus } from './services/webhooks';

const tones: Record<WebhookStatus, 'good' | 'bad' | 'warn' | 'neutral'> = { processed: 'good', failed: 'bad', pending: 'warn', ignored: 'neutral' };
const message = (e: unknown) => (e instanceof ApiError ? e.message : 'Something went wrong.');

function EventDetail({ id, onClose }: { id: string | null; onClose: () => void }) {
    const { data, isLoading, error } = useWebhookEvent(id);

    return (
        <Dialog open={id !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{data?.type ?? 'Event'}</DialogTitle>
                    <DialogDescription>{data?.stripe_event_id}</DialogDescription>
                </DialogHeader>
                {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}
                {error && <Alert>{message(error)}</Alert>}
                {data?.error && <Alert>{data.error}</Alert>}
                {data && <pre className="overflow-x-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(data.payload, null, 2)}</pre>}
            </DialogContent>
        </Dialog>
    );
}

export function WebhooksPage() {
    const list = useListState({ sort: 'created_at', direction: 'desc' });
    const { data, isLoading, error, refetch } = useWebhookEvents(list.params);
    const replay = useReplayEvent();
    const [viewing, setViewing] = useState<string | null>(null);
    const [actionError, setActionError] = useState<string>();

    async function retry(event: WebhookEvent) {
        setActionError(undefined);
        try {
            await replay.mutateAsync(event.id);
            toast.success(`${event.type} replayed`);
        } catch (e) {
            setActionError(message(e));
        }
    }

    const columns: Column<WebhookEvent>[] = [
        {
            id: 'event',
            header: 'Event',
            cell: (e) => (
                <>
                    <span className="block font-medium">{e.type}</span>
                    <span className="block text-xs text-muted-foreground">{e.stripe_event_id}</span>
                </>
            ),
        },
        { id: 'status', header: 'Status', sortKey: 'status', cell: (e) => <StatusBadge label={webhookStatusLabels[e.status]} tone={tones[e.status]} /> },
        { id: 'attempts', header: 'Attempts', cell: (e) => <span>{e.attempts}</span>, className: 'tabular-nums' },
        { id: 'error', header: 'Error', cell: (e) => (e.error ? <span className="text-xs text-destructive">{e.error}</span> : '—') },
        { id: 'received', header: 'Received', sortKey: 'created_at', cell: (e) => formatDateTime(e.created_at), className: 'whitespace-nowrap' },
        {
            id: 'actions',
            header: '',
            className: 'text-right',
            cell: (e) => (
                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" aria-label={`View ${e.stripe_event_id}`} onClick={() => setViewing(e.id)}>
                        <Eye aria-hidden /> View
                    </Button>
                    {e.status !== 'processed' && (
                        <Button variant="outline" size="sm" aria-label={`Replay ${e.stripe_event_id}`} disabled={replay.isPending} onClick={() => retry(e)}>
                            <RotateCw aria-hidden /> Replay
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <>
            <PageHeader title="Webhooks" description="Every event Stripe sent us. Failed events can be replayed once the cause is fixed." />

            {actionError && (
                <div className="mb-4">
                    <Alert>{actionError}</Alert>
                </div>
            )}

            <div className="mb-4 flex flex-wrap items-center gap-4">
                <SearchInput label="Search events" onSearch={list.setSearch} />
                <FilterSelect
                    label="Status"
                    value={list.state.filters.status ?? ''}
                    options={(Object.keys(webhookStatusLabels) as WebhookStatus[]).map((s) => ({ value: s, label: webhookStatusLabels[s] }))}
                    allLabel="All statuses"
                    onChange={(value) => list.setFilter('status', value)}
                />
            </div>

            <DataTable
                columns={columns}
                rows={data?.data}
                meta={data?.meta}
                rowKey={(e) => e.id}
                isLoading={isLoading}
                error={error ? message(error) : undefined}
                onRetry={() => refetch()}
                sort={list.state.sort}
                direction={list.state.direction}
                onSortChange={list.setSort}
                onPageChange={list.setPage}
                emptyTitle="No events yet"
                emptyHint="Events appear here as soon as Stripe starts sending them."
            />

            <EventDetail id={viewing} onClose={() => setViewing(null)} />
        </>
    );
}
