import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface StripeInfo {
    configured: boolean;
    mode: 'test' | 'live' | null;
    webhook_url: string;
    connect_webhook_url: string;
    webhook_secret_set: boolean;
    connect_webhook_secret_set: boolean;
}

export const useStripeInfo = () => useQuery({ queryKey: ['admin', 'stripe'], queryFn: () => api.get<StripeInfo>('/admin/stripe') });
