import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type ListParams } from '@/lib/api';

export type PlanStatus = 'draft' | 'active' | 'archived';

export interface Price {
    id: string;
    amount: number;
    currency: string;
    interval: string;
    interval_count: number;
    status: 'active' | 'archived';
    synced: boolean;
}

export interface Plan {
    id: string;
    name: string;
    description: string | null;
    features: string[];
    status: PlanStatus;
    visibility: 'public' | 'unlisted';
    trial_days: number;
    sort_order: number;
    prices: Price[];
    created_at: string;
}

export interface PlanInput {
    name: string;
    description: string | null;
    features: string[];
    trial_days: number;
    visibility: 'public' | 'unlisted';
}

export interface PriceInput {
    amount: number;
    currency: string;
    interval: string;
    interval_count: number;
}

export const planStatusLabels: Record<PlanStatus, string> = { draft: 'Draft', active: 'Live', archived: 'Archived' };

const key = ['provider', 'plans'] as const;

export const usePlans = (params: ListParams) =>
    useQuery({ queryKey: [...key, params], queryFn: () => api.list<Plan>('/provider/plans', params), placeholderData: keepPreviousData });

function useInvalidate() {
    const client = useQueryClient();
    return () => client.invalidateQueries({ queryKey: key });
}

export function usePlanMutations() {
    const invalidate = useInvalidate();
    const settle = { onSuccess: invalidate };

    return {
        create: useMutation({ mutationFn: (input: PlanInput) => api.post<Plan>('/provider/plans', input), ...settle }),
        update: useMutation({ mutationFn: ({ id, ...input }: PlanInput & { id: string }) => api.patch<Plan>(`/provider/plans/${id}`, input), ...settle }),
        publish: useMutation({ mutationFn: (id: string) => api.post<Plan>(`/provider/plans/${id}/publish`), ...settle }),
        archive: useMutation({ mutationFn: (id: string) => api.post<Plan>(`/provider/plans/${id}/archive`), ...settle }),
        remove: useMutation({ mutationFn: (id: string) => api.delete(`/provider/plans/${id}`), ...settle }),
        addPrice: useMutation({
            mutationFn: ({ planId, ...input }: PriceInput & { planId: string }) => api.post<Price>(`/provider/plans/${planId}/prices`, input),
            ...settle,
        }),
        changePrice: useMutation({
            mutationFn: ({ planId, priceId, ...input }: PriceInput & { planId: string; priceId: string }) =>
                api.patch<Price>(`/provider/plans/${planId}/prices/${priceId}`, input),
            ...settle,
        }),
        removePrice: useMutation({
            mutationFn: ({ planId, priceId }: { planId: string; priceId: string }) => api.delete(`/provider/plans/${planId}/prices/${priceId}`),
            ...settle,
        }),
    };
}
