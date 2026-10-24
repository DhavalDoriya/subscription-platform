import axios, { AxiosError, type AxiosRequestConfig, type InternalAxiosRequestConfig } from 'axios';
import { ApiError } from './ApiError';
import type { ErrorResponse, ItemResponse, ListParams, PageResponse } from './types';

export const http = axios.create({
    baseURL: '/api/v1',
    headers: { Accept: 'application/json' },
    withCredentials: true, // refresh-token cookie
});

type TokenGetter = () => string | null;
let getAccessToken: TokenGetter = () => null;

/** The auth store registers itself here (Phase 1); the client stays free of store imports. */
export function setAccessTokenGetter(getter: TokenGetter): void {
    getAccessToken = getter;
}

http.interceptors.request.use((config: InternalAxiosRequestConfig) => {
    const token = getAccessToken();
    if (token) config.headers.set('Authorization', `Bearer ${token}`);
    return config;
});

type RefreshHandler = () => Promise<string | null>;
let refreshHandler: RefreshHandler | null = null;

/** Returns a fresh access token (or null when the session is gone). Must be single-flight. */
export function setRefreshHandler(handler: RefreshHandler | null): void {
    refreshHandler = handler;
}

type RetryableConfig = InternalAxiosRequestConfig & { _retried?: boolean };

http.interceptors.response.use(undefined, async (error: unknown) => {
    if (error instanceof AxiosError && error.response?.status === 401 && refreshHandler && error.config) {
        const config = error.config as RetryableConfig;
        const isAuthCall = (config.url ?? '').startsWith('/auth/') && !(config.url ?? '').startsWith('/auth/me');

        if (!config._retried && !isAuthCall) {
            config._retried = true;
            const token = await refreshHandler();
            if (token) {
                config.headers.set('Authorization', `Bearer ${token}`);
                return http(config);
            }
        }
    }
    throw error;
});

function toApiError(error: unknown): ApiError {
    if (error instanceof ApiError) return error;
    if (error instanceof AxiosError) {
        return error.response
            ? ApiError.fromBody(error.response.status, error.response.data as Partial<ErrorResponse> | undefined)
            : ApiError.network();
    }
    return new ApiError('Something went wrong.', 0);
}

async function unwrap<R>(request: Promise<{ data: R }>): Promise<R> {
    try {
        return (await request).data;
    } catch (error) {
        throw toApiError(error);
    }
}

/** Typed helpers. Items resolve to `data`, lists to `{ data, meta }`; failures reject with ApiError. */
export const api = {
    get: async <T>(url: string, config?: AxiosRequestConfig) => (await unwrap<ItemResponse<T>>(http.get(url, config))).data,
    list: <T>(url: string, params?: ListParams) => unwrap<PageResponse<T>>(http.get(url, { params })),
    post: async <T>(url: string, body?: unknown) => (await unwrap<ItemResponse<T>>(http.post(url, body))).data,
    put: async <T>(url: string, body?: unknown) => (await unwrap<ItemResponse<T>>(http.put(url, body))).data,
    patch: async <T>(url: string, body?: unknown) => (await unwrap<ItemResponse<T>>(http.patch(url, body))).data,
    delete: async (url: string) => {
        await unwrap(http.delete(url));
    },
};
