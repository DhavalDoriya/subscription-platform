import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useEffect } from 'react';
import { BrowserRouter } from 'react-router-dom';
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
                <AppRoutes />
            </BrowserRouter>
        </QueryClientProvider>
    );
}
