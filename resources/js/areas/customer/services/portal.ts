import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type ListParams } from '@/lib/api';
import type { Invoice, PaymentMethod, Subscription } from '@/lib/billing';

export interface PlanOption {
    price_id: string;
    plan_id: string;
    plan_name: string;
    amount: number;
    currency: string;
    interval: string;
    interval_count: number;
}

export type ChangeTiming = 'immediate' | 'next_cycle';

export interface ChangePreview {
    timing: ChangeTiming;
    credit: number;
    charge: number;
    net: number;
    currency: string;
    effective_at: string | null;
}

export interface CardSetup {
    client_secret: string;
    stripe_account: string;
    publishable_key: string;
}

const root = ['customer'] as const;

export const useMySubscriptions = () =>
    useQuery({ queryKey: [...root, 'subscriptions'], queryFn: () => api.list<Subscription>('/customer/subscriptions', { per_page: 100 }) });

export const useMyInvoices = (params: ListParams) =>
    useQuery({ queryKey: [...root, 'invoices', params], queryFn: () => api.list<Invoice>('/customer/invoices', params), placeholderData: keepPreviousData });

export const useMyPaymentMethods = () =>
    useQuery({ queryKey: [...root, 'payment-methods'], queryFn: () => api.get<PaymentMethod[]>('/customer/payment-methods') });

export interface PaymentIssue {
    invoice_id: string;
    subscription_id: string;
    subscription_status: string | null;
    amount_due: number;
    currency: string;
    dunning_status: string;
    decline_type: 'soft' | 'hard' | 'auth_required' | null;
    failure_message: string | null;
    next_retry_at: string | null;
    grace_ends_at: string | null;
    hosted_invoice_url: string | null;
    plan: { name: string | null };
    provider: { id: string; name: string; slug: string };
}

export const usePaymentIssues = () => useQuery({ queryKey: [...root, 'payment-issues'], queryFn: () => api.get<PaymentIssue[]>('/customer/payment-issues') });

export const usePlanOptions = (subscriptionId: string) =>
    useQuery({ queryKey: [...root, 'options', subscriptionId], queryFn: () => api.get<PlanOption[]>(`/customer/subscriptions/${subscriptionId}/options`) });

export const useChangePreview = (subscriptionId: string, priceId: string, timing: ChangeTiming) =>
    useQuery({
        queryKey: [...root, 'preview', subscriptionId, priceId, timing],
        queryFn: () => api.post<ChangePreview>(`/customer/subscriptions/${subscriptionId}/change/preview`, { price_id: priceId, timing }),
        enabled: priceId !== '',
    });

export function usePortalMutations() {
    const client = useQueryClient();
    const settle = { onSuccess: () => client.invalidateQueries({ queryKey: root }) };

    return {
        cancel: useMutation({
            mutationFn: ({ id, when }: { id: string; when: 'period_end' | 'now' }) => api.post<Subscription>(`/customer/subscriptions/${id}/cancel`, { when }),
            ...settle,
        }),
        resume: useMutation({ mutationFn: (id: string) => api.post<Subscription>(`/customer/subscriptions/${id}/resume`), ...settle }),
        change: useMutation({
            mutationFn: ({ id, priceId, timing }: { id: string; priceId: string; timing: ChangeTiming }) =>
                api.post<Subscription>(`/customer/subscriptions/${id}/change`, { price_id: priceId, timing }),
            ...settle,
        }),
        makeDefault: useMutation({ mutationFn: (id: string) => api.post<PaymentMethod>(`/customer/payment-methods/${id}/default`), ...settle }),
        removeCard: useMutation({ mutationFn: (id: string) => api.delete(`/customer/payment-methods/${id}`), ...settle }),
        setupCard: useMutation({
            mutationFn: (providerId: string) => api.post<CardSetup>('/customer/payment-methods/setup-intent', { provider_id: providerId }),
        }),
    };
}

/** A card added in Stripe reaches us through a webhook a moment later, so refresh the list now and again shortly after. */
export function useRefreshCardsSoon() {
    const client = useQueryClient();

    return () => {
        const refresh = () => client.invalidateQueries({ queryKey: [...root, 'payment-methods'] });
        void refresh();
        setTimeout(refresh, 2000);
    };
}
