import { useEffect } from 'react';
import { toast } from 'sonner';
import { PaymentForm } from '@/components/billing/PaymentForm';
import { Alert } from '@/components/ui/Alert';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { ApiError } from '@/lib/api';
import { usePortalMutations, useRefreshCardsSoon } from './services/portal';

function Body({ provider, onClose }: { provider: { id: string; name: string }; onClose: () => void }) {
    const { setupCard } = usePortalMutations();
    const refreshSoon = useRefreshCardsSoon();
    const { mutate } = setupCard;

    useEffect(() => mutate(provider.id), [mutate, provider.id]);

    return (
        <>
            <DialogHeader>
                <DialogTitle>Add a card</DialogTitle>
                <DialogDescription>This card is saved for your subscriptions with {provider.name}.</DialogDescription>
            </DialogHeader>
            {setupCard.error && <Alert>{setupCard.error instanceof ApiError ? setupCard.error.message : 'Something went wrong.'}</Alert>}
            {setupCard.isPending && <Skeleton className="h-32 w-full" />}
            {setupCard.data && (
                <PaymentForm
                    publishableKey={setupCard.data.publishable_key}
                    stripeAccount={setupCard.data.stripe_account}
                    clientSecret={setupCard.data.client_secret}
                    type="setup"
                    returnUrl={`${window.location.origin}/account`}
                    submitLabel="Save card"
                    onSuccess={() => {
                        toast.success('Card saved');
                        refreshSoon();
                        onClose();
                    }}
                />
            )}
        </>
    );
}

/** Adds a card to one provider's Stripe customer with the Payment Element (FR-C7). */
export function AddCardDialog({ provider, onClose }: { provider: { id: string; name: string } | null; onClose: () => void }) {
    return (
        <Dialog open={provider !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent>{provider && <Body provider={provider} onClose={onClose} />}</DialogContent>
        </Dialog>
    );
}
