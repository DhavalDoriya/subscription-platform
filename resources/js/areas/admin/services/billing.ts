import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type ListParams } from '@/lib/api';
import type { Invoice, Subscription } from '@/lib/billing';

export const useAdminSubscriptions = (params: ListParams) =>
    useQuery({
        queryKey: ['admin', 'subscriptions', params],
        queryFn: () => api.list<Subscription>('/admin/subscriptions', params),
        placeholderData: keepPreviousData,
    });

export const useAdminInvoices = (params: ListParams) =>
    useQuery({ queryKey: ['admin', 'invoices', params], queryFn: () => api.list<Invoice>('/admin/invoices', params), placeholderData: keepPreviousData });

export function useAdminCancelSubscription() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: ({ id, when }: { id: string; when: 'period_end' | 'now' }) => api.post<Subscription>(`/admin/subscriptions/${id}/cancel`, { when }),
        onSuccess: () => client.invalidateQueries({ queryKey: ['admin', 'subscriptions'] }),
    });
}
