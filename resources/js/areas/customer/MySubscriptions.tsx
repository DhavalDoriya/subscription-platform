import { useState } from 'react';
import { toast } from 'sonner';
import { SubscriptionStatusBadge } from '@/components/billing/StatusBadges';
import { Alert } from '@/components/ui/Alert';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { ApiError } from '@/lib/api';
import type { Subscription } from '@/lib/billing';
import { formatDate } from '@/lib/format';
import { formatPrice } from '@/lib/money';
import { ChangePlanDialog } from './ChangePlanDialog';
import { usePortalMutations, useMySubscriptions } from './services/portal';

const canCancel = (s: Subscription) => ['trialing', 'active', 'past_due', 'grace', 'paused'].includes(s.status);
const canChange = (s: Subscription) => ['trialing', 'active'].includes(s.status);

function CancelDialog({ subscription, onClose }: { subscription: Subscription | null; onClose: () => void }) {
    const { cancel } = usePortalMutations();
    const [pending, setPending] = useState<'period_end' | 'now' | null>(null);
    const [error, setError] = useState<string>();

    async function run(when: 'period_end' | 'now') {
        setPending(when);
        setError(undefined);
        try {
            await cancel.mutateAsync({ id: subscription!.id, when });
            toast.success(when === 'now' ? 'Subscription canceled' : 'Your subscription will end at the end of the period');
            onClose();
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'Something went wrong.');
        } finally {
            setPending(null);
        }
    }

    return (
        <AlertDialog open={subscription !== null} onOpenChange={(open) => !open && onClose()}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Cancel {subscription?.plan?.name}?</AlertDialogTitle>
                    <AlertDialogDescription>
                        Ending at the period end keeps your access until {formatDate(subscription?.current_period_end)} and you will not be billed again.
                        Cancelling now ends your access immediately.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                {error && <Alert>{error}</Alert>}
                <AlertDialogFooter>
                    <AlertDialogCancel>Keep subscription</AlertDialogCancel>
                    <Button variant="outline" loading={pending === 'period_end'} onClick={() => run('period_end')}>
                        Cancel at period end
                    </Button>
                    <Button variant="destructive" loading={pending === 'now'} onClick={() => run('now')}>
                        Cancel now
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/** My subscriptions across providers (FR-CP1): status, next billing date, cancel, resume and change plan. */
export function MySubscriptions() {
    const { data, isLoading, error } = useMySubscriptions();
    const { resume } = usePortalMutations();
    const [canceling, setCanceling] = useState<Subscription | null>(null);
    const [changing, setChanging] = useState<Subscription | null>(null);

    if (isLoading) return <Skeleton className="h-32 w-full" />;
    if (error) return <Alert>{error instanceof ApiError ? error.message : 'Something went wrong.'}</Alert>;

    const subscriptions = data?.data ?? [];

    if (subscriptions.length === 0)
        return <p className="rounded-lg border border-dashed p-6 text-center text-muted-foreground">You haven&apos;t subscribed to anything yet.</p>;

    return (
        <>
            <div className="grid gap-4">
                {subscriptions.map((s) => (
                    <article key={s.id} aria-labelledby={`sub-${s.id}`} className="rounded-lg border bg-card p-5">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 id={`sub-${s.id}`} className="text-lg font-semibold">
                                    {s.plan?.name}
                                </h3>
                                <p className="text-sm text-muted-foreground">{s.provider?.name}</p>
                            </div>
                            <SubscriptionStatusBadge subscription={s} />
                        </div>
                        <p className="mt-3 text-sm">{formatPrice(s)}</p>
                        {s.current_period_end && s.status !== 'canceled' && (
                            <p className="text-sm text-muted-foreground">
                                {s.cancel_at_period_end ? 'Ends' : 'Renews'} {formatDate(s.current_period_end)}
                            </p>
                        )}
                        <div className="mt-4 flex flex-wrap gap-2">
                            {s.cancel_at_period_end && canCancel(s) && (
                                <Button
                                    size="sm"
                                    loading={resume.isPending && resume.variables === s.id}
                                    onClick={() =>
                                        resume.mutateAsync(s.id).then(
                                            () => toast.success('Subscription resumed'),
                                            (e) => toast.error(e instanceof ApiError ? e.message : 'Something went wrong.'),
                                        )
                                    }
                                >
                                    Resume
                                </Button>
                            )}
                            {canChange(s) && (
                                <Button size="sm" variant="outline" onClick={() => setChanging(s)}>
                                    Change plan
                                </Button>
                            )}
                            {canCancel(s) && !s.cancel_at_period_end && (
                                <Button size="sm" variant="outline" onClick={() => setCanceling(s)}>
                                    Cancel subscription
                                </Button>
                            )}
                        </div>
                    </article>
                ))}
            </div>

            <CancelDialog subscription={canceling} onClose={() => setCanceling(null)} />
            <ChangePlanDialog subscription={changing} onClose={() => setChanging(null)} />
        </>
    );
}
