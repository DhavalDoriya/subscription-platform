import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, type ListParams } from '@/lib/api';

export interface AuditEntry {
    id: string;
    description: string;
    causer: { id: string; name: string; email: string } | null;
    subject_type: string | null;
    subject_id: string | null;
    properties: Record<string, unknown>;
    created_at: string;
}

export const useAuditLogs = (params: ListParams) =>
    useQuery({ queryKey: ['admin', 'audit', params], queryFn: () => api.list<AuditEntry>('/admin/audit-logs', params), placeholderData: keepPreviousData });

/** One short line per change, for the table: status moves, setting changes, or nothing. */
export function summarize(entry: AuditEntry): string[] {
    const { from, to, changes } = entry.properties as { from?: unknown; to?: unknown; changes?: Record<string, unknown> };

    if (typeof from === 'string' && typeof to === 'string') return [`${from} → ${to}`];
    if (from && to && typeof from === 'object' && typeof to === 'object') {
        return Object.keys(to).map((k) => `${k}: ${String((from as Record<string, unknown>)[k])} → ${String((to as Record<string, unknown>)[k])}`);
    }
    if (changes) return Object.entries(changes).map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : String(v)}`);

    return [];
}
