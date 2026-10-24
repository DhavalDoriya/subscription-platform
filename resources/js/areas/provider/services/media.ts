import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type ListParams } from '@/lib/api';

export interface Media {
    id: string;
    url: string;
    original_name: string;
    mime_type: string;
    size: number;
    width: number;
    height: number;
    alt: string | null;
    created_at: string;
}

const key = ['provider', 'media'] as const;

export const useMedia = (params: ListParams, enabled = true) =>
    useQuery({ queryKey: [...key, params], queryFn: () => api.list<Media>('/provider/media', params), placeholderData: keepPreviousData, enabled });

export function useMediaMutations() {
    const client = useQueryClient();
    const settle = { onSuccess: () => client.invalidateQueries({ queryKey: key }) };

    return {
        upload: useMutation({
            mutationFn: (file: File) => {
                const body = new FormData();
                body.append('file', file);
                return api.post<Media>('/provider/media', body);
            },
            ...settle,
        }),
        remove: useMutation({ mutationFn: (id: string) => api.delete(`/provider/media/${id}`), ...settle }),
    };
}
