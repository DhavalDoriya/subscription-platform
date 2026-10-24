import { useEffect, useState } from 'react';
import { PaymentForm } from '@/components/billing/PaymentForm';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { api, ApiError } from '@/lib/api';
import { logout, useAuthStore } from '@/lib/auth';
import type { Subscription } from '@/lib/billing';
import { formatMoney, formatPrice } from '@/lib/money';
import type { StorefrontPlan } from '../types';
import { ActivationStep } from './ActivationStep';
import { AuthStep } from './AuthStep';

interface Started {
    subscription: Subscription;
    client_secret: string;
    client_secret_type: 'payment' | 'setup';
    stripe_account: string;
    publishable_key: string;
}

type Selection = { plan: StorefrontPlan; price: StorefrontPlan['prices'][number] };

function Flow({ providerName, plan, price, onClose }: Selection & { providerName: string; onClose: () => void }) {
    const status = useAuthStore((s) => s.status);
    const user = useAuthStore((s) => s.user);
    const [started, setStarted] = useState<Started | null>(null);
    const [paid, setPaid] = useState(false);
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string>();
    const trial = plan.trial_days > 0;

    // A different person (or a signed-out visitor) starts again from the beginning.
    useEffect(() => {
        if (status === 'guest') setStarted(null);
    }, [status]);

    async function join() {
        setPending(true);
        setError(undefined);
        try {
            setStarted(await api.post<Started>('/customer/subscriptions', { price_id: price.id }));
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'Something went wrong.');
        } finally {
            setPending(false);
        }
    }

    if (paid && started) return <ActivationStep subscriptionId={started.subscription.id} planName={plan.name} />;

    if (started)
        return (
            <>
                <DialogHeader>
                    <DialogTitle>Pay for {plan.name}</DialogTitle>
                    <DialogDescription>
                        {trial
                            ? `Your ${plan.trial_days}-day free trial starts now. You won't be charged until it ends.`
                            : `${formatPrice(price)}. You can cancel any time.`}
                    </DialogDescription>
                </DialogHeader>
                <PaymentForm
                    publishableKey={started.publishable_key}
                    stripeAccount={started.stripe_account}
                    clientSecret={started.client_secret}
                    type={started.client_secret_type}
                    returnUrl={`${window.location.origin}/?checkout=return`}
                    submitLabel={
                        started.client_secret_type === 'setup' ? (trial ? 'Start free trial' : 'Save card') : `Pay ${formatMoney(price.amount, price.currency)}`
                    }
                    onSuccess={() => setPaid(true)}
                />
            </>
        );

    if (status === 'unknown')
        return (
            <>
                <DialogHeader>
                    <DialogTitle>Subscribe to {plan.name}</DialogTitle>
                </DialogHeader>
                <Skeleton className="h-24 w-full" />
            </>
        );

    if (status !== 'authenticated' || !user)
        return (
            <>
                <DialogHeader>
                    <DialogTitle>Sign in to subscribe</DialogTitle>
                    <DialogDescription>One account works with every business on the platform. You only join {providerName} when you confirm.</DialogDescription>
                </DialogHeader>
                <AuthStep />
            </>
        );

    return (
        <>
            <DialogHeader>
                <DialogTitle>Subscribe to {plan.name}</DialogTitle>
                <DialogDescription>
                    {providerName} · {formatPrice(price)}
                </DialogDescription>
            </DialogHeader>
            {trial && <p className="rounded-md bg-muted px-4 py-3 text-sm font-medium">{plan.trial_days}-day free trial. No charge today.</p>}
            {error && <Alert>{error}</Alert>}
            <Button size="lg" loading={pending} onClick={join}>
                Continue as {user.name}
            </Button>
            <button type="button" onClick={() => logout()} className="text-sm text-muted-foreground hover:text-foreground hover:underline">
                Not {user.name}? Use a different account
            </button>
            <Button variant="ghost" onClick={onClose}>
                Cancel
            </Button>
        </>
    );
}

/** The whole join flow in one dialog: sign in or create an account, confirm, pay (3-D Secure included), then wait for activation. */
export function CheckoutDialog({ providerName, selection, onClose }: { providerName: string; selection: Selection | null; onClose: () => void }) {
    return (
        <Dialog open={selection !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                {selection && <Flow key={`${selection.plan.id}-${selection.price.id}`} providerName={providerName} onClose={onClose} {...selection} />}
            </DialogContent>
        </Dialog>
    );
}
