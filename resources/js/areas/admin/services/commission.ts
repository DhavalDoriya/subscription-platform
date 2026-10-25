import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type ListParams, type PageResponse } from '@/lib/api';

export interface CommissionRule {
    id: string;
    provider_id: string | null;
    provider?: { id: string; name: string; slug: string } | null;
    /** Percent with two decimals, e.g. "10.00". */
    percent: string;
    effective_from: string;
    in_effect: boolean;
    created_at: string;
}

export interface NewCommissionRule {
    percent: string;
    provider_id?: string;
    effective_from?: string;
}

export interface CommissionRow {
    id: string;
    payment_id: string;
    currency: string;
    gross_amount: number;
    refunded_amount: number;
    rate_percent: string;
    commission_amount: number;
    commission_refunded: number;
    net_commission: number;
    provider_net: number;
    created_at: string;
    provider?: { id: string; name: string; slug: string };
}

export interface CommissionTotals {
    currency: string;
    gross: number;
    refunded: number;
    commission: number;
    commission_refunded: number;
    net_commission: number;
}

export type CommissionReport = PageResponse<CommissionRow> & { totals: CommissionTotals[] };

const key = ['admin', 'commission'] as const;

export const useCommissionRules = (params: ListParams) =>
    useQuery({
        queryKey: [...key, 'rules', params],
        queryFn: () => api.list<CommissionRule>('/admin/commission/rules', params),
        placeholderData: keepPreviousData,
    });

export const useCommissionReport = (params: ListParams) =>
    useQuery({
        queryKey: [...key, 'report', params],
        queryFn: () => api.list<CommissionRow>('/admin/commission/report', params) as Promise<CommissionReport>,
        placeholderData: keepPreviousData,
    });

export function useCommissionRuleMutations() {
    const client = useQueryClient();
    const settle = { onSuccess: () => client.invalidateQueries({ queryKey: key }) };

    return {
        add: useMutation({ mutationFn: (body: NewCommissionRule) => api.post<CommissionRule>('/admin/commission/rules', body), ...settle }),
        remove: useMutation({ mutationFn: (id: string) => api.delete(`/admin/commission/rules/${id}`), ...settle }),
    };
}
