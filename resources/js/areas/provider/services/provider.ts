import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import { isValidSlug } from '@/lib/slug';

export type ProviderStatus = 'pending' | 'approved' | 'active' | 'inactive' | 'suspended' | 'rejected';

export interface MyProvider {
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
    branding: { primary_color: string | null };
    legal: { terms_url: string | null; privacy_url: string | null; refund_url: string | null };
    tax_enabled: boolean;
}

export interface PlatformConfig {
    platform_name: string;
    default_currency: string;
    allowed_currencies: string[];
    base_domain?: string;
    tax_available?: boolean;
    allowed_intervals?: string[];
    min_price?: number;
    max_price?: number;
}

export interface RegisterProviderInput {
    name: string;
    slug: string;
    business_category: string;
    country: string;
    support_email: string;
    currency: string;
}

export type SaveProviderInput = Partial<Pick<MyProvider, 'name' | 'support_email' | 'timezone' | 'currency'>> & {
    branding?: { primary_color: string | null };
    legal?: MyProvider['legal'];
    tax_enabled?: boolean;
};

const key = ['provider'] as const;

export const useMyProvider = () => useQuery({ queryKey: key, queryFn: () => api.get<MyProvider>('/provider'), retry: false });

export const usePlatformConfig = () =>
    useQuery({ queryKey: ['public', 'config'], queryFn: () => api.get<PlatformConfig>('/public/config'), staleTime: 5 * 60_000 });

export const registerProvider = (input: RegisterProviderInput) => api.post<MyProvider>('/provider/register', input);

export function useSaveProvider() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: SaveProviderInput) => api.patch<MyProvider>('/provider', input),
        onSuccess: (data) => client.setQueryData(key, data),
    });
}

/** Where a provider's storefront lives, e.g. acme.platform.com. */
export const storefrontHost = (slug: string, config?: PlatformConfig) => `${slug}.${config?.base_domain ?? window.location.hostname}`;

/** Live check of an address, once typing has paused and only for well-formed values. */
export function useSlugAvailability(slug: string, delay = 400) {
    const [settled, setSettled] = useState(slug);

    useEffect(() => {
        const timer = setTimeout(() => setSettled(slug), delay);
        return () => clearTimeout(timer);
    }, [slug, delay]);

    return useQuery({
        queryKey: ['public', 'slug', settled],
        queryFn: () => api.get<{ available: boolean; reason: 'invalid' | 'reserved' | 'taken' | null }>(`/public/slugs/${settled}/availability`),
        enabled: settled === slug && isValidSlug(slug),
        staleTime: 30_000,
    });
}
