import { useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { Alert } from '@/components/ui/Alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { ApiError } from '@/lib/api';
import type { PaymentMethod } from '@/lib/billing';
import { AddCardDialog } from './AddCardDialog';
import { useMyPaymentMethods, useMySubscriptions, usePortalMutations } from './services/portal';

const brandName = (brand: string | null) => (brand ? brand.charAt(0).toUpperCase() + brand.slice(1) : 'Card');

/** Saved cards, grouped by provider because each provider has its own Stripe account (FR-C7). */
export function PaymentMethods() {
    const methods = useMyPaymentMethods();
    const subscriptions = useMySubscriptions();
    const { makeDefault, removeCard } = usePortalMutations();
    const [removing, setRemoving] = useState<PaymentMethod | null>(null);
    const [adding, setAdding] = useState<{ id: string; name: string } | null>(null);

    if (methods.isLoading || subscriptions.isLoading) return <Skeleton className="h-24 w-full" />;
    if (methods.error) return <Alert>{methods.error instanceof ApiError ? methods.error.message : 'Something went wrong.'}</Alert>;

    // Providers I can add a card for: those I hold a subscription with, plus any that already have a card.
    const providers = new Map<string, { id: string; name: string }>();
    for (const s of subscriptions.data?.data ?? []) if (s.provider) providers.set(s.provider.id, s.provider);
    for (const m of methods.data ?? []) if (m.provider) providers.set(m.provider.id, m.provider);

    if (providers.size === 0) return <p className="rounded-lg border border-dashed p-6 text-center text-muted-foreground">Cards you save will appear here.</p>;

    return (
        <>
            <div className="grid gap-6">
                {[...providers.values()].map((provider) => {
                    const cards = (methods.data ?? []).filter((m) => m.provider?.id === provider.id);

                    return (
                        <section key={provider.id} aria-label={provider.name}>
                            <div className="mb-2 flex items-center justify-between gap-3">
                                <h3 className="font-semibold">{provider.name}</h3>
                                <Button size="sm" variant="outline" aria-label={`Add a card for ${provider.name}`} onClick={() => setAdding(provider)}>
                                    Add a card
                                </Button>
                            </div>
                            {cards.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No saved cards.</p>
                            ) : (
                                <ul className="divide-y rounded-lg border bg-card">
                                    {cards.map((card) => (
                                        <li
                                            key={card.id}
                                            aria-label={`${brandName(card.brand)} ending ${card.last4}`}
                                            className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                                        >
                                            <div className="text-sm">
                                                <p className="font-medium">
                                                    {brandName(card.brand)} •••• {card.last4} {card.is_default && <Badge variant="secondary">Default</Badge>}
                                                </p>
                                                <p className="text-muted-foreground">
                                                    Expires {String(card.exp_month).padStart(2, '0')}/{card.exp_year}
                                                </p>
                                            </div>
                                            <div className="flex gap-2">
                                                {!card.is_default && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        loading={makeDefault.isPending && makeDefault.variables === card.id}
                                                        onClick={() =>
                                                            makeDefault.mutateAsync(card.id).then(
                                                                () => toast.success('Default card updated'),
                                                                (e) => toast.error(e instanceof ApiError ? e.message : 'Something went wrong.'),
                                                            )
                                                        }
                                                    >
                                                        Make default
                                                    </Button>
                                                )}
                                                <Button size="sm" variant="outline" onClick={() => setRemoving(card)}>
                                                    Remove
                                                </Button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    );
                })}
            </div>

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title="Remove this card?"
                description={`${brandName(removing?.brand ?? null)} ending ${removing?.last4 ?? ''} will no longer be used for ${removing?.provider?.name ?? 'this provider'}.`}
                confirmLabel="Remove card"
                destructive
                onConfirm={async () => {
                    await removeCard.mutateAsync(removing!.id);
                    toast.success('Card removed');
                }}
            />
            <AddCardDialog provider={adding} onClose={() => setAdding(null)} />
        </>
    );
}
