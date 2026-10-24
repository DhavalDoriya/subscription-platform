import { useState } from 'react';
import { toast } from 'sonner';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ApiError } from '@/lib/api';
import type { Subscription } from '@/lib/billing';
import { formatDate } from '@/lib/format';
import { formatMoney, formatPrice } from '@/lib/money';
import { useFormSubmit } from '@/lib/useFormSubmit';
import { useChangePreview, usePlanOptions, usePortalMutations, type ChangeTiming } from './services/portal';

const selectClass = 'h-11 rounded-md border bg-card px-3 text-base font-normal';

function Body({ subscription, onClose }: { subscription: Subscription; onClose: () => void }) {
    const { data: options, error } = usePlanOptions(subscription.id);
    const { change } = usePortalMutations();
    const [priceId, setPriceId] = useState('');
    const [timing, setTiming] = useState<ChangeTiming>('immediate');
    const preview = useChangePreview(subscription.id, priceId, timing);
    const form = useFormSubmit(
        () => change.mutateAsync({ id: subscription.id, priceId, timing }),
        () => {
            toast.success('Plan changed');
            onClose();
        },
    );
    const quote = preview.data;

    return (
        <form onSubmit={form.submit} noValidate className="grid gap-4">
            <DialogHeader>
                <DialogTitle>Change plan</DialogTitle>
                <DialogDescription>
                    You are on {subscription.plan?.name} at {formatPrice(subscription)}.
                </DialogDescription>
            </DialogHeader>
            {(error || form.formError) && <Alert>{form.formError ?? (error instanceof ApiError ? error.message : 'Something went wrong.')}</Alert>}

            <label className="grid gap-1.5 text-sm font-medium">
                New plan
                <select value={priceId} onChange={(e) => setPriceId(e.target.value)} className={selectClass}>
                    <option value="">Choose a plan</option>
                    {options?.map((o) => (
                        <option key={o.price_id} value={o.price_id}>
                            {o.plan_name} · {formatPrice(o)}
                        </option>
                    ))}
                </select>
            </label>
            {options?.length === 0 && <p className="text-sm text-muted-foreground">There are no other plans to switch to right now.</p>}

            <label className="grid gap-1.5 text-sm font-medium">
                When to switch
                <select value={timing} onChange={(e) => setTiming(e.target.value as ChangeTiming)} className={selectClass}>
                    <option value="immediate">Now, paying only the difference</option>
                    <option value="next_cycle">At my next renewal</option>
                </select>
            </label>

            {quote && timing === 'immediate' && (
                <dl className="grid gap-1 rounded-md bg-muted px-4 py-3 text-sm">
                    <div className="flex justify-between">
                        <dt>Credit for unused time</dt>
                        <dd>-{formatMoney(quote.credit, quote.currency)}</dd>
                    </div>
                    <div className="flex justify-between">
                        <dt>New plan for the rest of this period</dt>
                        <dd>{formatMoney(quote.charge, quote.currency)}</dd>
                    </div>
                    <div className="flex justify-between border-t pt-1 font-semibold">
                        <dt>{quote.net >= 0 ? 'Due today' : 'Credit on your next invoice'}</dt>
                        <dd>{formatMoney(Math.abs(quote.net), quote.currency)}</dd>
                    </div>
                </dl>
            )}
            {quote && timing === 'next_cycle' && (
                <p className="rounded-md bg-muted px-4 py-3 text-sm">
                    Nothing to pay today. The new price starts at your next renewal on {formatDate(quote.effective_at)}.
                </p>
            )}

            <DialogFooter>
                <Button type="button" variant="outline" onClick={onClose}>
                    Keep my plan
                </Button>
                <Button type="submit" loading={form.pending} disabled={priceId === ''}>
                    Confirm change
                </Button>
            </DialogFooter>
        </form>
    );
}

/** Switch to another plan of the same provider, with the prorated cost shown first (FR-C3). */
export function ChangePlanDialog({ subscription, onClose }: { subscription: Subscription | null; onClose: () => void }) {
    return (
        <Dialog open={subscription !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent>{subscription && <Body subscription={subscription} onClose={onClose} />}</DialogContent>
        </Dialog>
    );
}
