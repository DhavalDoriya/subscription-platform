import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type ListParams } from '@/lib/api';

export interface Customer {
    id: string;
    name: string;
    email: string;
    email_verified_at: string | null;
    is_blocked: boolean;
    blocked_at: string | null;
    created_at: string;
    providers?: { id: string; name: string; slug: string; joined_at: string }[];
}

const key = ['admin', 'customers'] as const;

export const useCustomers = (params: ListParams) =>
    useQuery({ queryKey: [...key, 'list', params], queryFn: () => api.list<Customer>('/admin/customers', params), placeholderData: keepPreviousData });

export const useCustomer = (id: string | null) =>
    useQuery({ queryKey: [...key, 'detail', id], queryFn: () => api.get<Customer>(`/admin/customers/${id}`), enabled: id !== null });

export function useBlockCustomer() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: ({ id, blocked }: { id: string; blocked: boolean }) => api.post<Customer>(`/admin/customers/${id}/${blocked ? 'block' : 'unblock'}`),
        onSuccess: () => client.invalidateQueries({ queryKey: key }),
    });
}
