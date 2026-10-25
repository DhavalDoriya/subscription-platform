import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type ListParams } from '@/lib/api';
import type { Invoice, Payment, Refund, Subscription } from '@/lib/billing';

export interface Overview {
    currency: string;
    mrr: number;
    active_subscribers: number;
    new_30d: number;
    churned_30d: number;
    failed_payments_30d: number;
    upcoming_renewals_7d: number;
    commission: { rate_percent: string; fee_30d: number };
    dunning: { open_invoices: number; open_amount: number; at_risk_mrr: number; recovered_30d: number; lost_30d: number; recovery_rate: number | null };
}

export type SubscriptionDetail = Subscription & { invoices: Invoice[]; payments: Payment[] };

const root = ['provider', 'billing'] as const;

export const useOverview = () => useQuery({ queryKey: [...root, 'overview'], queryFn: () => api.get<Overview>('/provider/overview') });

export const useSubscriptions = (params: ListParams) =>
    useQuery({
        queryKey: [...root, 'subscriptions', params],
        queryFn: () => api.list<Subscription>('/provider/subscriptions', params),
        placeholderData: keepPreviousData,
    });

export const useSubscriptionDetail = (id: string | null) =>
    useQuery({ queryKey: [...root, 'subscription', id], queryFn: () => api.get<SubscriptionDetail>(`/provider/subscriptions/${id}`), enabled: id !== null });

export const usePayments = (params: ListParams) =>
    useQuery({ queryKey: [...root, 'payments', params], queryFn: () => api.list<Payment>('/provider/payments', params), placeholderData: keepPreviousData });

export const useInvoices = (params: ListParams) =>
    useQuery({ queryKey: [...root, 'invoices', params], queryFn: () => api.list<Invoice>('/provider/invoices', params), placeholderData: keepPreviousData });

export function useBillingMutations() {
    const client = useQueryClient();
    const settle = { onSuccess: () => client.invalidateQueries({ queryKey: root }) };

    return {
        cancel: useMutation({
            mutationFn: ({ id, when }: { id: string; when: 'period_end' | 'now' }) => api.post<Subscription>(`/provider/subscriptions/${id}/cancel`, { when }),
            ...settle,
        }),
        refund: useMutation({
            mutationFn: ({ id, amount, reason }: { id: string; amount: number; reason: string | null }) =>
                api.post<Refund>(`/provider/payments/${id}/refund`, { amount, reason }),
            ...settle,
        }),
    };
}
