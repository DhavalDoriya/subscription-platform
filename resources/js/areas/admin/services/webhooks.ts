import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type ListParams } from '@/lib/api';

export type WebhookStatus = 'pending' | 'processed' | 'failed' | 'ignored';

export interface WebhookEvent {
    id: string;
    stripe_event_id: string;
    type: string;
    account_id: string | null;
    livemode: boolean;
    status: WebhookStatus;
    attempts: number;
    error: string | null;
    processed_at: string | null;
    created_at: string;
    payload?: unknown;
}

export const webhookStatusLabels: Record<WebhookStatus, string> = { pending: 'Pending', processed: 'Processed', failed: 'Failed', ignored: 'Ignored' };

const key = ['admin', 'webhooks'] as const;

export const useWebhookEvents = (params: ListParams) =>
    useQuery({ queryKey: [...key, 'list', params], queryFn: () => api.list<WebhookEvent>('/admin/webhook-events', params), placeholderData: keepPreviousData });

export const useWebhookEvent = (id: string | null) =>
    useQuery({ queryKey: [...key, 'detail', id], queryFn: () => api.get<WebhookEvent>(`/admin/webhook-events/${id}`), enabled: id !== null });

export function useReplayEvent() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.post<WebhookEvent>(`/admin/webhook-events/${id}/replay`),
        onSuccess: () => client.invalidateQueries({ queryKey: key }),
    });
}
