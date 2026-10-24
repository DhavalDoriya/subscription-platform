import { useState } from 'react';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/api';
import { redirectTo } from '@/lib/navigate';
import type { ProviderStatus } from './services/provider';
import { useStripeActions } from './services/stripe';

const copy: Record<string, { title: string; body: string }> = {
    not_started: {
        title: 'Connect Stripe to accept payments',
        body: 'Stripe handles card payments and payouts. Setup takes a few minutes and you can leave and come back.',
    },
    pending: { title: 'Finish setting up your Stripe account', body: 'Stripe still needs a few details before you can accept payments.' },
    restricted: {
        title: 'Stripe needs more information',
        body: 'Payments are limited until you provide what Stripe asks for. Fix the account, or open your Stripe dashboard to see what is missing.',
    },
    enabled: {
        title: 'Payments are enabled',
        body: 'You can publish plans and customers can subscribe. Manage payouts and bank details in your Stripe dashboard.',
    },
};

export function StripeCard({ providerStatus, stripeStatus }: { providerStatus: ProviderStatus; stripeStatus: string }) {
    const { onboarding, dashboard } = useStripeActions();
    const [error, setError] = useState<string>();
    const approved = providerStatus === 'approved' || providerStatus === 'active';
    const text = copy[stripeStatus] ?? copy.not_started;
    const busy = onboarding.isPending || dashboard.isPending;

    async function go(action: typeof onboarding | typeof dashboard) {
        setError(undefined);
        try {
            const { url } = await action.mutateAsync();
            redirectTo(url);
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'Something went wrong.');
        }
    }

    return (
        <section aria-label="Payments" className="mt-6 max-w-2xl rounded-lg border bg-card p-6">
            <h2 className="text-lg font-semibold">{text.title}</h2>
            <p className="mt-1 text-sm text-muted-foreground">{text.body}</p>
            {!approved && stripeStatus === 'not_started' && (
                <p className="mt-2 text-sm text-muted-foreground">You can connect Stripe after your account is approved.</p>
            )}
            {error && (
                <div className="mt-3">
                    <Alert>{error}</Alert>
                </div>
            )}
            <div className="mt-4 flex flex-wrap gap-2">
                {stripeStatus === 'not_started' && (
                    <Button disabled={!approved} loading={onboarding.isPending} onClick={() => go(onboarding)}>
                        Connect Stripe
                    </Button>
                )}
                {stripeStatus === 'pending' && (
                    <Button loading={onboarding.isPending} onClick={() => go(onboarding)}>
                        Continue setup
                    </Button>
                )}
                {stripeStatus === 'restricted' && (
                    <Button loading={onboarding.isPending} onClick={() => go(onboarding)}>
                        Fix account
                    </Button>
                )}
                {(stripeStatus === 'restricted' || stripeStatus === 'enabled') && (
                    <Button variant="outline" disabled={busy} loading={dashboard.isPending} onClick={() => go(dashboard)}>
                        Open Stripe dashboard
                    </Button>
                )}
            </div>
        </section>
    );
}
