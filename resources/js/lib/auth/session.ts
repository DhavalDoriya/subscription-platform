import { api, setAccessTokenGetter, setRefreshHandler } from '@/lib/api';
import { useAuthStore } from './authStore';
import type { AuthPayload } from './types';

function accept(payload: AuthPayload): AuthPayload {
    useAuthStore.getState().setSession(payload.user, payload.access_token);
    return payload;
}

let inflight: Promise<string | null> | null = null;

/** Single-flight: parallel 401s share one refresh call (the refresh token rotates, so a second call would trip reuse detection). */
export function refreshSession(): Promise<string | null> {
    inflight ??= api
        .post<AuthPayload>('/auth/refresh')
        .then((payload) => accept(payload).access_token)
        .catch(() => {
            useAuthStore.getState().clear();
            return null;
        })
        .finally(() => {
            inflight = null;
        });

    return inflight;
}

export const login = (email: string, password: string) => api.post<AuthPayload>('/auth/login', { email, password }).then(accept);

export const register = (data: { name: string; email: string; password: string; password_confirmation: string }) =>
    api.post<AuthPayload>('/auth/register', data).then(accept);

export async function logout(): Promise<void> {
    try {
        await api.post('/auth/logout');
    } finally {
        useAuthStore.getState().clear();
    }
}

/** Call once at startup: wires the HTTP client to the store and restores a session from the refresh cookie. */
export async function bootstrapAuth(): Promise<void> {
    setAccessTokenGetter(() => useAuthStore.getState().accessToken);
    setRefreshHandler(refreshSession);
    await refreshSession();
}
