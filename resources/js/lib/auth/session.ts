import { api, setAccessTokenGetter, setRefreshHandler } from '@/lib/api';
import { useAuthStore } from './authStore';
import type { AuthPayload, AuthUser, ImpersonationPayload } from './types';

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

const acceptUser = (user: AuthUser): AuthUser => {
    useAuthStore.getState().setUser(user);
    return user;
};

export const updateProfile = (data: { name?: string; email?: string; current_password?: string }) =>
    api.patch<AuthUser>('/auth/profile', data).then(acceptUser);

/** Revokes every other session server-side and returns a fresh one for this device. */
export const changePassword = (data: { current_password: string; password: string; password_confirmation: string }) =>
    api.put<AuthPayload>('/auth/password', data).then(accept);

export const uploadAvatar = (file: File) => {
    const form = new FormData();
    form.append('avatar', file);
    return api.post<AuthUser>('/auth/avatar', form).then(acceptUser);
};

export const removeAvatar = () =>
    api
        .delete('/auth/avatar')
        .then(() => api.get<AuthUser>('/auth/me'))
        .then(acceptUser);

/** Admin acts as `userId`. The API issues a short-lived token with no refresh token, so it simply ends when it expires. */
export async function impersonate(userId: string): Promise<AuthUser> {
    const payload = await api.post<ImpersonationPayload>(`/admin/users/${userId}/impersonate`);
    useAuthStore.getState().setSession(payload.user, payload.access_token, payload.impersonator);
    return payload.user;
}

/** Audits the end, then recovers the admin's own session from their (untouched) refresh cookie. */
export async function endImpersonation(): Promise<void> {
    try {
        await api.post('/auth/impersonation/end');
    } finally {
        await refreshSession();
    }
}

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
