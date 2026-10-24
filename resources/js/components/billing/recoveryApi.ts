import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { DunningTimeline, Invoice } from '@/lib/billing';

type Base = '/provider' | '/admin';

/** Provider and admin share the same actions on different paths; their cached lists live under ['provider'] and ['admin']. */
export function useRecoveryMutations(base: Base) {
    const client = useQueryClient();
    const settle = { onSuccess: () => client.invalidateQueries({ queryKey: [base.slice(1)] }) };

    return {
        retry: useMutation({ mutationFn: (id: string) => api.post<Invoice>(`${base}/invoices/${id}/retry`), ...settle }),
        resolve: useMutation({ mutationFn: (id: string) => api.post<Invoice>(`${base}/invoices/${id}/resolve`), ...settle }),
    };
}

export const useDunningTimeline = (base: Base, invoiceId: string, enabled: boolean) =>
    useQuery({ queryKey: [base.slice(1), 'dunning', invoiceId], queryFn: () => api.get<DunningTimeline>(`${base}/invoices/${invoiceId}/dunning`), enabled });
