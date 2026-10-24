import { create } from 'zustand';
import type { AuthUser, Impersonator } from './types';

/** `unknown` until the first refresh attempt finishes, so guards don't flash the login page. */
export type AuthStatus = 'unknown' | 'authenticated' | 'guest';

interface AuthState {
    status: AuthStatus;
    user: AuthUser | null;
    /** Kept in memory only: never in localStorage (XSS). The refresh cookie restores it on reload. */
    accessToken: string | null;
    /** Set while an admin is acting as another user (FR-A10). */
    impersonator: Impersonator | null;
    setSession: (user: AuthUser, accessToken: string, impersonator?: Impersonator | null) => void;
    setUser: (user: AuthUser) => void;
    clear: () => void;
}

export const useAuthStore = create<AuthState>((set) => ({
    status: 'unknown',
    user: null,
    accessToken: null,
    impersonator: null,
    setSession: (user, accessToken, impersonator = null) => set({ status: 'authenticated', user, accessToken, impersonator }),
    setUser: (user) => set({ user }),
    clear: () => set({ status: 'guest', user: null, accessToken: null, impersonator: null }),
}));
