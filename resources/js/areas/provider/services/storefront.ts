import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { BlockType, StorefrontSeo } from '@/areas/storefront/types';
import type { Data } from '../storefront/blockSchemas';

export interface DraftBlock {
    id?: string;
    type: BlockType;
    visible: boolean;
    data: Data;
}

export interface StorefrontDraft {
    seo: StorefrontSeo;
    blocks: (DraftBlock & { id: string })[];
    published_at: string | null;
    has_unpublished_changes: boolean;
}

export interface DraftInput {
    seo: StorefrontSeo;
    blocks: DraftBlock[];
}

const key = ['provider', 'storefront'] as const;

export const useStorefrontDraft = () => useQuery({ queryKey: key, queryFn: () => api.get<StorefrontDraft>('/provider/storefront') });

export function useStorefrontActions() {
    const client = useQueryClient();
    const store = (draft: StorefrontDraft) => client.setQueryData(key, draft);

    return {
        save: useMutation({ mutationFn: (input: DraftInput) => api.put<StorefrontDraft>('/provider/storefront', input), onSuccess: store }),
        publish: useMutation({ mutationFn: () => api.post<StorefrontDraft>('/provider/storefront/publish'), onSuccess: store }),
        unpublish: useMutation({ mutationFn: () => api.post<StorefrontDraft>('/provider/storefront/unpublish'), onSuccess: store }),
        preview: useMutation({ mutationFn: () => api.post<{ url: string; token: string; expires_at: string }>('/provider/storefront/preview') }),
    };
}
