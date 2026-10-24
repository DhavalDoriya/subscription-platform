import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface StripeStatus {
    status: string;
    connected: boolean;
    charges_enabled?: boolean;
    payouts_enabled?: boolean;
    details_submitted?: boolean;
    currently_due?: string[];
}

/** Ask Stripe for the account's real state and store it (used when the provider comes back from onboarding). */
export const refreshStripeStatus = () => api.get<StripeStatus>('/provider/stripe', { params: { refresh: 1 } });

export function useStripeActions() {
    const client = useQueryClient();
    const settle = { onSuccess: () => client.invalidateQueries({ queryKey: ['provider'] }) };

    return {
        onboarding: useMutation({ mutationFn: () => api.post<{ url: string }>('/provider/stripe/onboarding'), ...settle }),
        dashboard: useMutation({ mutationFn: () => api.post<{ url: string }>('/provider/stripe/dashboard') }),
    };
}
