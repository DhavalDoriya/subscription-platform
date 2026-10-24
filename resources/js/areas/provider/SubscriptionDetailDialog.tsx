import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { InvoiceStatusBadge, PaymentStatusBadge, SubscriptionStatusBadge } from '@/components/billing/StatusBadges';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { ApiError } from '@/lib/api';
import type { Payment } from '@/lib/billing';
import { formatDate } from '@/lib/format';
import { formatMoney, formatPrice } from '@/lib/money';
import { RefundDialog } from './RefundDialog';
import { useBillingMutations, useSubscriptionDetail } from './services/billing';

const canRefund = (p: Payment) => ['succeeded', 'partially_refunded'].includes(p.status) && p.refundable > 0;

/** One subscriber's history (FR-PD3): their subscription, invoices and payments, with cancel and refund actions. */
export function SubscriptionDetailDialog({ subscriptionId, onClose }: { subscriptionId: string | null; onClose: () => void }) {
    const { data: sub, error } = useSubscriptionDetail(subscriptionId);
    const { cancel } = useBillingMutations();
    const [refunding, setRefunding] = useState<Payment | null>(null);
    const [canceling, setCanceling] = useState<'period_end' | 'now' | null>(null);
    const live = sub && ['trialing', 'active', 'past_due', 'grace', 'paused'].includes(sub.status);

    return (
        <>
            <Dialog open={subscriptionId !== null} onOpenChange={(open) => !open && onClose()}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{sub?.customer?.name ?? 'Subscriber'}</DialogTitle>
                        <DialogDescription>{sub?.customer?.email}</DialogDescription>
                    </DialogHeader>
                    {error && <Alert>{error instanceof ApiError ? error.message : 'Something went wrong.'}</Alert>}
                    {!sub && !error && <Skeleton className="h-40 w-full" />}
                    {sub && (
                        <div className="grid gap-6">
                            <dl className="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <dt className="text-muted-foreground">Plan</dt>
                                    <dd className="font-medium">
                                        {sub.plan?.name} · {formatPrice(sub)}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Status</dt>
                                    <dd>
                                        <SubscriptionStatusBadge subscription={sub} />
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">{sub.cancel_at_period_end ? 'Ends' : 'Renews'}</dt>
                                    <dd>{formatDate(sub.current_period_end)}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Since</dt>
                                    <dd>{formatDate(sub.created_at)}</dd>
                                </div>
                            </dl>

                            {live && (
                                <div className="flex flex-wrap gap-2">
                                    {!sub.cancel_at_period_end && (
                                        <Button variant="outline" onClick={() => setCanceling('period_end')}>
                                            Cancel at period end
                                        </Button>
                                    )}
                                    <Button variant="destructive" onClick={() => setCanceling('now')}>
                                        Cancel now
                                    </Button>
                                </div>
                            )}

                            <section aria-labelledby="sub-invoices">
                                <h3 id="sub-invoices" className="mb-2 font-semibold">
                                    Invoices
                                </h3>
                                {sub.invoices.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">No invoices yet.</p>
                                ) : (
                                    <ul className="divide-y rounded-md border">
                                        {sub.invoices.map((invoice) => (
                                            <li key={invoice.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                                <span>
                                                    <span className="font-medium">{invoice.number ?? 'Invoice'}</span>
                                                    <span className="ml-2 text-muted-foreground">{formatDate(invoice.created_at)}</span>
                                                </span>
                                                <span className="flex items-center gap-3">
                                                    {formatMoney(invoice.amount_due, invoice.currency)}
                                                    <InvoiceStatusBadge status={invoice.status} />
                                                    {invoice.hosted_invoice_url && (
                                                        <a
                                                            href={invoice.hosted_invoice_url}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            aria-label={`Open invoice ${invoice.number}`}
                                                            className="text-primary"
                                                        >
                                                            <ExternalLink aria-hidden className="size-4" />
                                                        </a>
                                                    )}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>

                            <section aria-labelledby="sub-payments">
                                <h3 id="sub-payments" className="mb-2 font-semibold">
                                    Payments
                                </h3>
                                {sub.payments.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">No payments yet.</p>
                                ) : (
                                    <ul className="divide-y rounded-md border">
                                        {sub.payments.map((payment) => (
                                            <li key={payment.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                                <span>
                                                    {formatMoney(payment.amount, payment.currency)}
                                                    <span className="ml-2 text-muted-foreground">{formatDate(payment.paid_at ?? payment.created_at)}</span>
                                                    {payment.failure_message && <span className="block text-destructive">{payment.failure_message}</span>}
                                                </span>
                                                <span className="flex items-center gap-3">
                                                    <PaymentStatusBadge status={payment.status} />
                                                    {canRefund(payment) && (
                                                        <Button size="sm" variant="outline" onClick={() => setRefunding(payment)}>
                                                            Refund {formatMoney(payment.refundable, payment.currency)}
                                                        </Button>
                                                    )}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            <RefundDialog payment={refunding} onClose={() => setRefunding(null)} />

            <ConfirmDialog
                open={canceling !== null}
                onOpenChange={(open) => !open && setCanceling(null)}
                title={canceling === 'now' ? 'Cancel this subscription now?' : 'Cancel at the end of the period?'}
                description={
                    canceling === 'now'
                        ? `${sub?.customer?.name ?? 'The subscriber'} loses access immediately and is not refunded automatically.`
                        : `${sub?.customer?.name ?? 'The subscriber'} keeps access until ${formatDate(sub?.current_period_end)} and is not billed again.`
                }
                confirmLabel={canceling === 'now' ? 'Cancel now' : 'Cancel at period end'}
                destructive={canceling === 'now'}
                onConfirm={async () => {
                    await cancel.mutateAsync({ id: sub!.id, when: canceling! });
                    toast.success(canceling === 'now' ? 'Subscription canceled' : 'Subscription will end at the period end');
                }}
            />
        </>
    );
}
