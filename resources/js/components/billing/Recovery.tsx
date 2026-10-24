import { CheckCheck, History, RotateCw } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { StatusBadge } from '@/components/StatusBadge';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { ApiError } from '@/lib/api';
import { dunningEventLabels, dunningStatuses, isDunningActive, type Invoice } from '@/lib/billing';
import { formatDate, formatDateTime } from '@/lib/format';
import { useDunningTimeline, useRecoveryMutations } from './recoveryApi';

/** Where a failed invoice is in the retry engine, and when the next try runs (FR-R12). */
export function RecoveryCell({ invoice }: { invoice: Pick<Invoice, 'dunning_status' | 'next_retry_at'> }) {
    if (!invoice.dunning_status) return <span className="text-muted-foreground">—</span>;

    const status = dunningStatuses[invoice.dunning_status];

    return (
        <div className="grid gap-0.5">
            <span>
                <StatusBadge label={status.label} tone={status.tone} />
            </span>
            {invoice.next_retry_at && <span className="text-xs text-muted-foreground">Next retry {formatDate(invoice.next_retry_at)}</span>}
        </div>
    );
}

const errorMessage = (e: unknown) => (e instanceof ApiError ? e.message : 'Something went wrong.');

/** Retry now, mark resolved and the history of one failed invoice (FR-R4, FR-R9). `basePath` is `/provider` or `/admin`. */
export function InvoiceRecoveryActions({ invoice, basePath }: { invoice: Invoice; basePath: '/provider' | '/admin' }) {
    const { retry, resolve } = useRecoveryMutations(basePath);
    const [resolving, setResolving] = useState(false);
    const [history, setHistory] = useState(false);

    if (!invoice.dunning_status) return null;

    const name = invoice.number ?? invoice.id.slice(0, 8);
    const active = isDunningActive(invoice.dunning_status);

    return (
        <div className="flex justify-end gap-1">
            {active && (
                <>
                    <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label={`Retry invoice ${name} now`}
                        title="Retry now"
                        loading={retry.isPending && retry.variables === invoice.id}
                        onClick={() =>
                            retry.mutateAsync(invoice.id).then(
                                (result) =>
                                    result.dunning_status === 'recovered' ? toast.success('Payment collected') : toast.error('The payment was declined again'),
                                (e) => toast.error(errorMessage(e)),
                            )
                        }
                    >
                        <RotateCw aria-hidden />
                    </Button>
                    <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label={`Mark invoice ${name} resolved`}
                        title="Mark resolved"
                        onClick={() => setResolving(true)}
                    >
                        <CheckCheck aria-hidden />
                    </Button>
                </>
            )}
            <Button size="icon-sm" variant="ghost" aria-label={`Show recovery history for ${name}`} title="History" onClick={() => setHistory(true)}>
                <History aria-hidden />
            </Button>

            <ConfirmDialog
                open={resolving}
                onOpenChange={setResolving}
                title="Mark this invoice resolved?"
                description="Use this when the customer has paid outside the platform. Retries stop and the subscription goes back to active. Nothing is charged."
                confirmLabel="Mark resolved"
                onConfirm={() => resolve.mutateAsync(invoice.id).then(() => toast.success('Marked resolved'))}
            />
            <DunningHistoryDialog open={history} onOpenChange={setHistory} invoice={invoice} basePath={basePath} />
        </div>
    );
}

function DunningHistoryDialog({
    open,
    onOpenChange,
    invoice,
    basePath,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    invoice: Invoice;
    basePath: '/provider' | '/admin';
}) {
    const { data, error, isPending } = useDunningTimeline(basePath, invoice.id, open);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Recovery history for {invoice.number ?? 'this invoice'}</DialogTitle>
                    <DialogDescription>Every try to collect this invoice and every notice we sent.</DialogDescription>
                </DialogHeader>
                {error && <Alert>{errorMessage(error)}</Alert>}
                {isPending && !error && <Skeleton className="h-24 w-full" />}
                {data && (
                    <div className="grid gap-5">
                        <section aria-label="Attempts">
                            <h3 className="mb-2 text-sm font-medium">Attempts</h3>
                            <ol className="grid gap-2">
                                {data.attempts.map((attempt) => (
                                    <li key={attempt.id} className="rounded-md border p-3 text-sm">
                                        <p className="flex items-center justify-between gap-2 font-medium">
                                            <span>Attempt {attempt.attempt_number}</span>
                                            <StatusBadge
                                                label={
                                                    attempt.status === 'succeeded'
                                                        ? 'Paid'
                                                        : attempt.status === 'requires_action'
                                                          ? 'Needs confirmation'
                                                          : 'Declined'
                                                }
                                                tone={attempt.status === 'succeeded' ? 'good' : 'bad'}
                                            />
                                        </p>
                                        <p className="text-muted-foreground">{formatDateTime(attempt.attempted_at)}</p>
                                        {attempt.decline_code && (
                                            <p>
                                                {attempt.failure_message ?? 'Declined'} <span className="text-muted-foreground">({attempt.decline_code})</span>
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        </section>
                        <section aria-label="Notices and decisions">
                            <h3 className="mb-2 text-sm font-medium">Notices and decisions</h3>
                            <ul className="grid gap-1 text-sm">
                                {data.events.map((event) => (
                                    <li key={event.id} className="flex justify-between gap-3">
                                        <span>{dunningEventLabels[event.type] ?? event.type}</span>
                                        <span className="text-muted-foreground">{formatDateTime(event.occurred_at)}</span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
