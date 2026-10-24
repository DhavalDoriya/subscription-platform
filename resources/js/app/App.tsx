import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useEffect } from 'react';
import { BrowserRouter } from 'react-router-dom';
import { ImpersonationBanner } from '@/components/ImpersonationBanner';
import { Toaster } from '@/components/ui/sonner';
import { StorefrontApp } from '@/areas/storefront/StorefrontApp';
import { bootstrapAuth } from '@/lib/auth';
import { storefrontSlug } from '@/lib/tenant';
import { AppRoutes } from './routes';

const queryClient = new QueryClient();

/** On `<slug>.<base domain>` this is the provider's public storefront; everywhere else it is the platform app. */
export function App({ host = window.location.hostname }: { host?: string }) {
    const isStorefront = storefrontSlug(host, window.__APP__?.baseDomain) !== null;

    useEffect(() => {
        // Storefronts restore a session too, so a signed-in customer can join with one click ("Continue as ...").
        void bootstrapAuth();
    }, []);

    if (isStorefront) {
        return (
            <QueryClientProvider client={queryClient}>
                <StorefrontApp />
            </QueryClientProvider>
        );
    }

    return (
        <QueryClientProvider client={queryClient}>
            <BrowserRouter>
                <ImpersonationBanner />
                <AppRoutes />
                <Toaster position="bottom-right" richColors />
            </BrowserRouter>
        </QueryClientProvider>
    );
}
