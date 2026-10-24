import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { ProviderStatus } from './providers';

export interface Dashboard {
    providers: { total: number; by_status: Record<ProviderStatus, number> };
    customers: { total: number };
    recent_providers: { id: string; name: string; slug: string; status: ProviderStatus; created_at: string }[];
    recent_customers: { id: string; name: string; email: string; created_at: string }[];
}

export const useDashboard = () => useQuery({ queryKey: ['admin', 'dashboard'], queryFn: () => api.get<Dashboard>('/admin/dashboard') });
