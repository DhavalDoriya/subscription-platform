import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { MemoryRouter } from 'react-router-dom';
import { useAuthStore, type Role } from '@/lib/auth';
import { AppRoutes } from './routes';

const url = (p: string) => `http://localhost/api/v1${p}`;
const server = setupServer(
    http.get(url('/admin/dashboard'), () =>
        HttpResponse.json({
            data: {
                providers: { total: 0, by_status: {} },
                customers: { total: 0 },
                dunning: { open_invoices: 0, open_amount: {}, at_risk_mrr: {}, recovered_30d: 0, lost_30d: 0, recovery_rate: null },
                recent_providers: [],
                recent_customers: [],
            },
        }),
    ),
    http.get(url('/customer/payment-issues'), () => HttpResponse.json({ data: [] })),
    http.get(url('/admin/admins'), () => HttpResponse.json({ data: [], meta: { current_page: 1, per_page: 15, total: 0, last_page: 1 } })),
);
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
afterEach(() => useAuthStore.getState().clear());
afterAll(() => server.close());

function renderAt(path: string, roles: Role[] | null) {
    if (roles) useAuthStore.getState().setSession({ id: 'u1', name: 'Ada', email: 'ada@example.com', email_verified_at: null, avatar_url: null, roles }, 't');
    else useAuthStore.getState().clear();
    return render(
        <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
            <MemoryRouter initialEntries={[path]}>
                <AppRoutes />
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('FR-A4 admin routes', () => {
    it('sends guests to sign in', () => {
        renderAt('/admin', null);
        expect(screen.getByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
    });

    it('keeps customers out of the admin panel', () => {
        renderAt('/admin/providers', ['customer']);
        expect(screen.queryByRole('navigation', { name: 'Admin' })).not.toBeInTheDocument();
    });

    it('shows admins the panel with the dashboard first', async () => {
        renderAt('/admin', ['admin']);
        expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument();
        expect(screen.getByRole('navigation', { name: 'Admin' })).toBeInTheDocument();
    });

    it('keeps the Admins page for super admins only', async () => {
        const { unmount } = renderAt('/admin/admins', ['admin']);
        expect(screen.queryByRole('heading', { name: 'Admins' })).not.toBeInTheDocument();
        unmount();
        renderAt('/admin/admins', ['super_admin']);
        expect(await screen.findByRole('heading', { name: 'Admins' })).toBeInTheDocument();
    });

    it('moves between pages from the sidebar without a reload', async () => {
        server.use(http.get(url('/admin/providers'), () => HttpResponse.json({ data: [], meta: { current_page: 1, per_page: 15, total: 0, last_page: 1 } })));
        renderAt('/admin', ['admin']);
        await screen.findByRole('heading', { name: 'Dashboard' });

        await userEvent.click(screen.getByRole('link', { name: 'Providers' }));

        expect(await screen.findByRole('heading', { name: 'Providers' })).toBeInTheDocument();
    });

    it('keeps customers out of the provider dashboard and lets provider owners in', async () => {
        server.use(
            http.get(url('/provider'), () =>
                HttpResponse.json({
                    data: {
                        id: 'p1',
                        name: 'Acme',
                        slug: 'acme',
                        status: 'active',
                        business_category: 'x',
                        country: 'US',
                        support_email: 'a@b.test',
                        currency: 'usd',
                        timezone: 'UTC',
                        stripe_status: 'not_started',
                        rejection_reason: null,
                        approved_at: null,
                        created_at: '2026-10-01T00:00:00Z',
                        branding: { primary_color: null },
                        legal: { terms_url: null, privacy_url: null, refund_url: null },
                    },
                }),
            ),
            http.get(url('/public/config'), () => HttpResponse.json({ data: { platform_name: 'P', default_currency: 'usd', allowed_currencies: ['usd'] } })),
        );
        const { unmount } = renderAt('/dashboard', ['customer']);
        expect(screen.queryByRole('navigation', { name: 'Provider' })).not.toBeInTheDocument();
        unmount();

        renderAt('/dashboard', ['customer', 'provider_owner']);
        expect(await screen.findByRole('navigation', { name: 'Provider' })).toBeInTheDocument();
    });

    it('lets any signed-in user open the provider registration page', async () => {
        server.use(
            http.get(url('/public/config'), () => HttpResponse.json({ data: { platform_name: 'P', default_currency: 'usd', allowed_currencies: ['usd'] } })),
        );
        renderAt('/become-a-provider', ['customer']);

        expect(await screen.findByRole('heading', { name: 'Become a provider' })).toBeInTheDocument();
    });
});

describe('FR-R3 fix payment route', () => {
    it('sends guests to sign in', () => {
        renderAt('/account/fix-payment', null);
        expect(screen.getByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
    });

    it('shows a signed-in customer the page the emails link to', async () => {
        renderAt('/account/fix-payment', ['customer']);
        expect(await screen.findByRole('heading', { name: 'Fix your payment' })).toBeInTheDocument();
    });
});
