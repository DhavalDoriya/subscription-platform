import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { setAccessTokenGetter, setRefreshHandler } from '@/lib/api';
import { useAuthStore } from './authStore';
import { endImpersonation, impersonate, refreshSession } from './session';

const url = (p: string) => `http://localhost/api/v1${p}`;
const admin = { id: 'a1', name: 'Ada Admin', email: 'ada@example.com', email_verified_at: null, avatar_url: null, roles: ['admin' as const] };
const target = { id: 'c1', name: 'Ann Lee', email: 'ann@example.com', email_verified_at: null, avatar_url: null, roles: ['customer' as const] };

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    setAccessTokenGetter(() => useAuthStore.getState().accessToken);
    setRefreshHandler(refreshSession);
    useAuthStore.getState().setSession(admin, 'admin-token');
});
afterEach(() => {
    server.resetHandlers();
    useAuthStore.getState().clear();
});
afterAll(() => server.close());

describe('FR-A10 impersonation session', () => {
    it('swaps to the target and remembers who is impersonating', async () => {
        server.use(
            http.post(url('/admin/users/c1/impersonate'), () =>
                HttpResponse.json({
                    data: { access_token: 'imp', token_type: 'bearer', expires_in: 900, user: target, impersonator: { id: 'a1', name: 'Ada Admin' } },
                }),
            ),
        );

        await impersonate('c1');

        const state = useAuthStore.getState();
        expect(state.user?.id).toBe('c1');
        expect(state.accessToken).toBe('imp');
        expect(state.impersonator).toEqual({ id: 'a1', name: 'Ada Admin' });
    });

    it('ends by telling the API, then getting the admin session back from the refresh cookie', async () => {
        let ended = false;
        server.use(
            http.post(url('/auth/impersonation/end'), ({ request }) => {
                ended = request.headers.get('authorization') === 'Bearer imp';
                return new HttpResponse(null, { status: 204 });
            }),
            http.post(url('/auth/refresh'), () =>
                HttpResponse.json({ data: { access_token: 'admin-fresh', token_type: 'bearer', expires_in: 900, user: admin } }),
            ),
        );
        useAuthStore.getState().setSession(target, 'imp', { id: 'a1', name: 'Ada Admin' });

        await endImpersonation();

        const state = useAuthStore.getState();
        expect(ended).toBe(true);
        expect(state.user?.id).toBe('a1');
        expect(state.accessToken).toBe('admin-fresh');
        expect(state.impersonator).toBeNull();
    });

    it('a normal refresh clears any impersonation (the cookie belongs to the admin)', async () => {
        server.use(
            http.post(url('/auth/refresh'), () => HttpResponse.json({ data: { access_token: 'x', token_type: 'bearer', expires_in: 900, user: admin } })),
        );
        useAuthStore.getState().setSession(target, 'imp', { id: 'a1', name: 'Ada Admin' });

        await refreshSession();

        expect(useAuthStore.getState().impersonator).toBeNull();
        expect(useAuthStore.getState().user?.id).toBe('a1');
    });
});
