import { PageHeader } from '@/components/PageHeader';
import { Alert } from '@/components/ui/Alert';
import { Skeleton } from '@/components/ui/skeleton';
import { ApiError } from '@/lib/api';
import { OverviewMetrics } from './OverviewMetrics';
import { StripeCard } from './StripeCard';
import { storefrontHost, useMyProvider, usePlatformConfig, type MyProvider } from './services/provider';

const messages: Record<MyProvider['status'], { title: string; body: string }> = {
    pending: { title: 'Waiting for approval', body: 'We are reviewing your account and will email you as soon as there is a decision.' },
    approved: { title: 'Approved', body: 'Your account is approved. Next you will connect Stripe so you can start selling.' },
    active: { title: 'Your account is live', body: 'Your storefront is open and customers can subscribe.' },
    inactive: {
        title: 'Your account is paused',
        body: 'Your storefront is unavailable and billing is paused for existing subscribers. Contact support to reactivate it.',
    },
    suspended: {
        title: 'Your account is paused',
        body: 'Your storefront is unavailable and billing is paused for existing subscribers. Contact support to find out why.',
    },
    rejected: { title: 'Your application was not approved', body: 'You can contact support if you think this is a mistake.' },
};

export function ProviderOverviewPage() {
    const { data: provider, error } = useMyProvider();
    const { data: config } = usePlatformConfig();

    if (error) return <Alert>{error instanceof ApiError ? error.message : 'Something went wrong.'}</Alert>;
    if (!provider) return <Skeleton className="h-40 w-full" />;

    const message = messages[provider.status];

    return (
        <>
            <PageHeader title="Overview" description={`Storefront address: ${storefrontHost(provider.slug, config)}`} />
            {provider.status === 'active' && <OverviewMetrics />}
            <section role="status" aria-label="Account status" className="max-w-2xl rounded-lg border bg-card p-6">
                <h2 className="text-lg font-semibold">{message.title}</h2>
                <p className="mt-1 text-sm text-muted-foreground">{message.body}</p>
                {provider.status === 'rejected' && provider.rejection_reason && (
                    <p className="mt-3 rounded-md bg-muted px-3 py-2 text-sm">
                        <span className="font-medium">Reason: </span>
                        {provider.rejection_reason}
                    </p>
                )}
            </section>
            {provider.status !== 'rejected' && <StripeCard providerStatus={provider.status} stripeStatus={provider.stripe_status} />}
        </>
    );
}
