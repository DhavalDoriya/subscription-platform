import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useEffect } from 'react';
import { BrowserRouter } from 'react-router-dom';
import { ImpersonationBanner } from '@/components/ImpersonationBanner';
import { Toaster } from '@/components/ui/sonner';
import { bootstrapAuth } from '@/lib/auth';
import { AppRoutes } from './routes';

const queryClient = new QueryClient();

export function App() {
    useEffect(() => {
        void bootstrapAuth();
    }, []);

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
