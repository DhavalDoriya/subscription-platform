import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type ListParams } from '@/lib/api';

export type ProviderStatus = 'pending' | 'approved' | 'active' | 'inactive' | 'suspended' | 'rejected';
export type ProviderAction = 'approve' | 'reject' | 'activate' | 'deactivate' | 'suspend';

export interface AdminProvider {
    id: string;
    name: string;
    slug: string;
    status: ProviderStatus;
    business_category: string;
    country: string;
    support_email: string;
    currency: string;
    timezone: string;
    stripe_status: string;
    rejection_reason: string | null;
    approved_at: string | null;
    created_at: string;
    customers_count: number;
    owner: { id: string; name: string; email: string } | null;
}

export const providerStatuses: { value: ProviderStatus; label: string }[] = [
    { value: 'pending', label: 'Pending' },
    { value: 'approved', label: 'Approved' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'suspended', label: 'Suspended' },
    { value: 'rejected', label: 'Rejected' },
];

/** Mirrors App\Enums\ProviderStatus::allowedTransitions(); the API still enforces it. */
export const providerActions: Record<ProviderStatus, ProviderAction[]> = {
    pending: ['approve', 'reject'],
    approved: ['activate', 'deactivate', 'suspend'],
    active: ['deactivate', 'suspend'],
    inactive: ['activate'],
    suspended: ['activate'],
    rejected: ['approve'],
};

const key = ['admin', 'providers'] as const;

export const useProviders = (params: ListParams) =>
    useQuery({ queryKey: [...key, params], queryFn: () => api.list<AdminProvider>('/admin/providers', params), placeholderData: keepPreviousData });

function useInvalidate() {
    const client = useQueryClient();
    return () => Promise.all([client.invalidateQueries({ queryKey: key }), client.invalidateQueries({ queryKey: ['admin', 'dashboard'] })]);
}

export function useProviderAction() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: ({ id, action, reason }: { id: string; action: ProviderAction; reason?: string }) =>
            api.post<AdminProvider>(`/admin/providers/${id}/${action}`, reason === undefined ? undefined : { reason }),
        onSuccess: invalidate,
    });
}

export function useUpdateProvider() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: ({ id, ...data }: { id: string; name: string; slug: string; support_email: string }) =>
            api.patch<AdminProvider>(`/admin/providers/${id}`, data),
        onSuccess: invalidate,
    });
}

/** Moderation: take the storefront offline without touching the provider's status or billing. */
export function useUnpublishStorefront() {
    const invalidate = useInvalidate();
    return useMutation({ mutationFn: (id: string) => api.post<{ published: false }>(`/admin/providers/${id}/storefront/unpublish`), onSuccess: invalidate });
}
