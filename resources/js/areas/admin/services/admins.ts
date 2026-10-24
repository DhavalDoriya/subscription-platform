import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type ListParams } from '@/lib/api';
import type { AuthUser } from '@/lib/auth';

const key = ['admin', 'admins'] as const;

export const useAdmins = (params: ListParams) =>
    useQuery({ queryKey: [...key, params], queryFn: () => api.list<AuthUser>('/admin/admins', params), placeholderData: keepPreviousData });

export function useAddAdmin() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (data: { name: string; email: string; password: string }) => api.post<AuthUser>('/admin/admins', data),
        onSuccess: () => client.invalidateQueries({ queryKey: key }),
    });
}

export function useRemoveAdmin() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.delete(`/admin/admins/${id}`),
        onSuccess: () => client.invalidateQueries({ queryKey: key }),
    });
}
