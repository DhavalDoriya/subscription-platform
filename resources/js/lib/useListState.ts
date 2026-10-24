import { useMemo, useState } from 'react';
import type { ListParams } from '@/lib/api';

interface State {
    page: number;
    search: string;
    sort?: string;
    direction: 'asc' | 'desc';
    filters: Record<string, string>;
}

/** Query state for a server-driven listing: any change to search, filters or sort goes back to page 1. */
export function useListState(defaults: { sort?: string; direction?: 'asc' | 'desc' } = {}) {
    const [state, setState] = useState<State>({ page: 1, search: '', sort: defaults.sort, direction: defaults.direction ?? 'asc', filters: {} });

    const params = useMemo<ListParams>(() => {
        const out: ListParams = { page: state.page, sort: state.sort, direction: state.direction };
        if (state.search) out.search = state.search;
        for (const [key, value] of Object.entries(state.filters)) if (value !== '') out[key] = value;
        return out;
    }, [state]);

    return {
        params,
        state,
        setPage: (page: number) => setState((s) => ({ ...s, page })),
        setSearch: (search: string) => setState((s) => ({ ...s, search, page: 1 })),
        setSort: (sort: string, direction: 'asc' | 'desc') => setState((s) => ({ ...s, sort, direction, page: 1 })),
        setFilter: (key: string, value: string) => setState((s) => ({ ...s, filters: { ...s.filters, [key]: value }, page: 1 })),
    };
}
