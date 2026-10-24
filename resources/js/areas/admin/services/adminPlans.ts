import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type ListParams } from '@/lib/api';
import type { Plan } from '@/areas/provider/services/plans';

export type AdminPlan = Plan & { provider: { id: string; name: string; slug: string } };

const key = ['admin', 'plans'] as const;

export const useAdminPlans = (params: ListParams) =>
    useQuery({ queryKey: [...key, params], queryFn: () => api.list<AdminPlan>('/admin/plans', params), placeholderData: keepPreviousData });

export function useForceArchive() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.post<AdminPlan>(`/admin/plans/${id}/archive`),
        onSuccess: () => client.invalidateQueries({ queryKey: key }),
    });
}
