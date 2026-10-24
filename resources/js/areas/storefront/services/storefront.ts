import { useQuery } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';
import type { StorefrontPayload } from '../types';

/** The storefront for the host the SPA was loaded from. `preview` is the token from a preview link (shows the draft). */
export function useStorefront(preview: string | null) {
    const query = useQuery({
        queryKey: ['storefront', preview],
        queryFn: () => api.get<StorefrontPayload>('/public/storefront', { params: preview ? { preview } : undefined }),
        retry: false,
    });

    return { ...query, notFound: query.error instanceof ApiError && query.error.status === 404 };
}
