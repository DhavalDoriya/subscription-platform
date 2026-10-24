import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuthStore } from './authStore';
import type { Role } from './types';

/** Requires a signed-in user, and optionally one of `roles`. */
export function RequireAuth({ roles, children }: { roles?: Role[]; children: ReactNode }) {
    const { status, user } = useAuthStore();
    const location = useLocation();

    if (status === 'unknown') return <p role="status">Loading…</p>;
    if (status === 'guest' || !user) return <Navigate to="/login" replace state={{ from: location.pathname }} />;
    if (roles && !roles.some((role) => user.roles.includes(role))) return <Navigate to="/" replace />;

    return <>{children}</>;
}

/** For pages like login/register: signed-in users are sent on to the app. */
export function GuestOnly({ children }: { children: ReactNode }) {
    const { status } = useAuthStore();

    if (status === 'unknown') return <p role="status">Loading…</p>;
    if (status === 'authenticated') return <Navigate to="/account" replace />;

    return <>{children}</>;
}
