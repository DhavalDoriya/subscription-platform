import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

/** { group: { name: { value } | { is_set } } } as returned by GET /admin/settings. */
export type SettingsPayload = Record<string, Record<string, { value?: unknown; is_set?: boolean }>>;

export interface SettingsUpdate {
    settings: Record<string, Record<string, unknown>>;
}

export const useSettings = () => useQuery({ queryKey: ['admin', 'settings'], queryFn: () => api.get<SettingsPayload>('/admin/settings') });

export function useSaveSettings() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (body: SettingsUpdate) => api.put<SettingsPayload>('/admin/settings', body),
        onSuccess: (data) => client.setQueryData(['admin', 'settings'], data),
    });
}
