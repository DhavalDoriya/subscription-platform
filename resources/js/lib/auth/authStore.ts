import { create } from 'zustand';
import type { AuthUser } from './types';

/** `unknown` until the first refresh attempt finishes, so guards don't flash the login page. */
export type AuthStatus = 'unknown' | 'authenticated' | 'guest';

interface AuthState {
    status: AuthStatus;
    user: AuthUser | null;
    /** Kept in memory only: never in localStorage (XSS). The refresh cookie restores it on reload. */
    accessToken: string | null;
    setSession: (user: AuthUser, accessToken: string) => void;
    clear: () => void;
}

export const useAuthStore = create<AuthState>((set) => ({
    status: 'unknown',
    user: null,
    accessToken: null,
    setSession: (user, accessToken) => set({ status: 'authenticated', user, accessToken }),
    clear: () => set({ status: 'guest', user: null, accessToken: null }),
}));
