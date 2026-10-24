import { http as mock, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { api, setAccessTokenGetter, setRefreshHandler } from '@/lib/api';
import { bootstrapAuth, login, logout, useAuthStore } from '.';

const user = { id: 'u1', name: 'Jane', email: 'jane@example.com', email_verified_at: null, roles: ['customer'] };
const payload = (token: string) => ({ data: { access_token: token, token_type: 'bearer', expires_in: 900, user } });
const url = (path: string) => `http://localhost/api/v1${path}`;

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
afterEach(() => {
    server.resetHandlers();
    useAuthStore.getState().clear();
    setAccessTokenGetter(() => null);
    setRefreshHandler(null);
});
afterAll(() => server.close());

describe('auth session', () => {
    it('login stores the user and token in memory', async () => {
        server.use(mock.post(url('/auth/login'), () => HttpResponse.json(payload('t1'))));

        await login('jane@example.com', 'Password123!');

        expect(useAuthStore.getState()).toMatchObject({ status: 'authenticated', accessToken: 't1', user: { id: 'u1' } });
    });

    it('bootstrap restores a session from the refresh cookie', async () => {
        server.use(mock.post(url('/auth/refresh'), () => HttpResponse.json(payload('t2'))));

        await bootstrapAuth();

        expect(useAuthStore.getState().status).toBe('authenticated');
        expect(useAuthStore.getState().accessToken).toBe('t2');
    });

    it('bootstrap marks the visitor as guest when there is no session', async () => {
        server.use(mock.post(url('/auth/refresh'), () => HttpResponse.json({ message: 'no' }, { status: 401 })));

        await bootstrapAuth();

        expect(useAuthStore.getState().status).toBe('guest');
    });

    it('refreshes once and retries when the access token has expired', async () => {
        let refreshCalls = 0;
        server.use(
            mock.post(url('/auth/refresh'), () => {
                refreshCalls++;
                return HttpResponse.json(payload('fresh'));
            }),
            mock.get(url('/things'), ({ request }) =>
                request.headers.get('authorization') === 'Bearer fresh'
                    ? HttpResponse.json({ data: ['ok'] })
                    : HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 }),
            ),
        );
        useAuthStore.getState().setSession(user as never, 'stale');
        await bootstrapAuth();
        refreshCalls = 0;
        useAuthStore.getState().setSession(user as never, 'stale');

        await expect(api.get('/things')).resolves.toEqual(['ok']);
        expect(refreshCalls).toBe(1);
    });

    it('shares one refresh between parallel 401s', async () => {
        let refreshCalls = 0;
        server.use(
            mock.post(url('/auth/refresh'), async () => {
                refreshCalls++;
                await new Promise((r) => setTimeout(r, 20));
                return HttpResponse.json(payload('fresh'));
            }),
            mock.get(url('/things'), ({ request }) =>
                request.headers.get('authorization') === 'Bearer fresh'
                    ? HttpResponse.json({ data: ['ok'] })
                    : HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 }),
            ),
        );
        setAccessTokenGetter(() => useAuthStore.getState().accessToken);
        const { refreshSession } = await import('./session');
        setRefreshHandler(refreshSession);
        useAuthStore.getState().setSession(user as never, 'stale');

        await Promise.all([api.get('/things'), api.get('/things'), api.get('/things')]);

        expect(refreshCalls).toBe(1);
    });

    it('signs the user out when the refresh fails', async () => {
        server.use(
            mock.post(url('/auth/refresh'), () => HttpResponse.json({ message: 'expired' }, { status: 401 })),
            mock.get(url('/things'), () => HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 })),
        );
        await bootstrapAuth();
        useAuthStore.getState().setSession(user as never, 'stale');

        await expect(api.get('/things')).rejects.toMatchObject({ status: 401 });
        expect(useAuthStore.getState().status).toBe('guest');
    });

    it('logout clears the store even if the request fails', async () => {
        server.use(mock.post(url('/auth/logout'), () => HttpResponse.error()));
        useAuthStore.getState().setSession(user as never, 't');

        await logout().catch(() => undefined);

        expect(useAuthStore.getState().status).toBe('guest');
    });
});
