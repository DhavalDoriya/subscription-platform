import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { MemoryRouter } from 'react-router-dom';
import { setAccessTokenGetter, setRefreshHandler } from '@/lib/api';
import { refreshSession, useAuthStore } from '@/lib/auth';
import { ImpersonationBanner } from './ImpersonationBanner';

const url = (p: string) => `http://localhost/api/v1${p}`;
const admin = { id: 'a1', name: 'Ada Admin', email: 'ada@example.com', email_verified_at: null, avatar_url: null, roles: ['admin' as const] };
const target = { id: 'c1', name: 'Ann Lee', email: 'ann@example.com', email_verified_at: null, avatar_url: null, roles: ['customer' as const] };

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    setAccessTokenGetter(() => useAuthStore.getState().accessToken);
    setRefreshHandler(refreshSession);
});
afterEach(() => {
    server.resetHandlers();
    useAuthStore.getState().clear();
});
afterAll(() => server.close());

const renderBanner = () =>
    render(
        <MemoryRouter>
            <ImpersonationBanner />
        </MemoryRouter>,
    );

describe('FR-A10 impersonation banner', () => {
    it('renders nothing in a normal session', () => {
        useAuthStore.getState().setSession(admin, 't');
        renderBanner();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('says who is being viewed and by whom, and exits back to the admin session', async () => {
        server.use(
            http.post(url('/auth/impersonation/end'), () => new HttpResponse(null, { status: 204 })),
            http.post(url('/auth/refresh'), () => HttpResponse.json({ data: { access_token: 'fresh', token_type: 'bearer', expires_in: 900, user: admin } })),
        );
        useAuthStore.getState().setSession(target, 'imp', { id: 'a1', name: 'Ada Admin' });
        renderBanner();

        expect(screen.getByRole('status')).toHaveTextContent('Ann Lee');
        expect(screen.getByRole('status')).toHaveTextContent('Ada Admin');
        await userEvent.click(screen.getByRole('button', { name: 'Exit' }));

        await waitFor(() => expect(useAuthStore.getState().user?.id).toBe('a1'));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });
});
