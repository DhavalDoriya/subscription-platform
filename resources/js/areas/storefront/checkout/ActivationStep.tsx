import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import { api } from '@/lib/api';
import type { Subscription } from '@/lib/billing';

const LIVE = ['trialing', 'active', 'past_due', 'grace'];
const GIVE_UP_AFTER_MS = 20_000;

/** The account page of the platform app (the storefront lives on a subdomain of it). */
export function accountUrl(): string {
    const base = window.__APP__?.baseDomain;

    return base ? `${window.location.protocol}//${base}${window.location.port ? `:${window.location.port}` : ''}/account` : '/account';
}

/**
 * Stripe confirms the payment in the browser, but our subscription turns live when Stripe's webhook reaches us a moment
 * later. Poll until it does; if it takes too long, say the payment was received rather than leave the customer guessing.
 */
export function ActivationStep({ subscriptionId, planName }: { subscriptionId: string; planName: string }) {
    const [gaveUp, setGaveUp] = useState(false);
    const { data } = useQuery({
        queryKey: ['customer', 'subscriptions', 'activation', subscriptionId],
        queryFn: () => api.list<Subscription>('/customer/subscriptions', { per_page: 100 }),
        refetchInterval: (query) => (query.state.data?.data.some((s) => s.id === subscriptionId && LIVE.includes(s.status)) ? false : 1500),
    });
    const live = data?.data.some((s) => s.id === subscriptionId && LIVE.includes(s.status));

    useEffect(() => {
        const timer = setTimeout(() => setGaveUp(true), GIVE_UP_AFTER_MS);
        return () => clearTimeout(timer);
    }, []);

    if (live)
        return (
            <div className="grid gap-3 text-center">
                <h2 className="text-xl font-semibold">You&apos;re subscribed to {planName}</h2>
                <p className="text-muted-foreground">A receipt is on its way to your email.</p>
                <a href={accountUrl()} className="font-medium text-primary hover:underline">
                    Manage your subscription
                </a>
            </div>
        );

    if (gaveUp)
        return (
            <div className="grid gap-3 text-center">
                <h2 className="text-xl font-semibold">Payment received</h2>
                <p className="text-muted-foreground">Your {planName} subscription is being set up and will appear in your account shortly.</p>
                <a href={accountUrl()} className="font-medium text-primary hover:underline">
                    Go to your account
                </a>
            </div>
        );

    return (
        <div role="status" className="grid gap-3 text-center">
            <Skeleton className="mx-auto h-6 w-48" />
            <p className="text-muted-foreground">Confirming your payment…</p>
        </div>
    );
}
