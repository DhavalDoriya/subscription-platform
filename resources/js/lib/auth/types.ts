export type Role = 'customer' | 'provider_owner' | 'admin' | 'super_admin';

export interface AuthUser {
    id: string;
    name: string;
    email: string;
    email_verified_at: string | null;
    avatar_url: string | null;
    roles: Role[];
}

/** Body of register / login / refresh. */
export interface AuthPayload {
    access_token: string;
    token_type: 'bearer';
    expires_in: number;
    user: AuthUser;
}

export interface Impersonator {
    id: string;
    name: string;
}

/** Body of POST /admin/users/{id}/impersonate. */
export interface ImpersonationPayload extends AuthPayload {
    impersonator: Impersonator;
}
