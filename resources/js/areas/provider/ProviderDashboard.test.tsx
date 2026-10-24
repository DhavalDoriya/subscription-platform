import { cleanup, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { Route, Routes } from 'react-router-dom';
import { useAuthStore } from '@/lib/auth';
import { renderWithProviders } from '@/test/render';
import { ProviderLayout } from './ProviderLayout';
import { ProviderOverviewPage } from './ProviderOverviewPage';
import { ProviderSettingsPage } from './ProviderSettingsPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const user = {
    id: 'u1',
    name: 'Olive Owner',
    email: 'olive@example.com',
    email_verified_at: null,
    avatar_url: null,
    roles: ['customer' as const, 'provider_owner' as const],
};
const provider = {
    id: 'p1',
    name: 'Acme Fitness',
    slug: 'acme',
    status: 'approved',
    business_category: 'fitness',
    country: 'US',
    support_email: 'help@acme.test',
    currency: 'usd',
    timezone: 'UTC',
    stripe_status: 'not_started',
    rejection_reason: null,
    approved_at: '2026-10-01T00:00:00Z',
    created_at: '2026-10-01T00:00:00Z',
    branding: { primary_color: null },
    legal: { terms_url: null, privacy_url: null, refund_url: null },
};

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    useAuthStore.getState().setSession(user, 't');
    server.use(
        http.get(url('/provider'), () => HttpResponse.json({ data: provider })),
        http.get(url('/provider/overview'), () =>
            HttpResponse.json({
                data: {
                    currency: 'usd',
                    mrr: 0,
                    active_subscribers: 0,
                    new_30d: 0,
                    churned_30d: 0,
                    failed_payments_30d: 0,
                    upcoming_renewals_7d: 0,
                    dunning: { open_invoices: 0, open_amount: 0, at_risk_mrr: 0, recovered_30d: 0, lost_30d: 0, recovery_rate: null },
                },
            }),
        ),
        http.get(url('/public/config'), () =>
            HttpResponse.json({ data: { platform_name: 'Billing Platform', default_currency: 'usd', allowed_currencies: ['usd', 'eur'] } }),
        ),
    );
});
afterEach(() => {
    server.resetHandlers();
    useAuthStore.getState().clear();
});
afterAll(() => server.close());

const setup = (route = '/dashboard') =>
    renderWithProviders(
        <Routes>
            <Route path="/dashboard" element={<ProviderLayout />}>
                <Route index element={<ProviderOverviewPage />} />
                <Route path="settings" element={<ProviderSettingsPage />} />
            </Route>
            <Route path="/" element={<h1>Home</h1>} />
        </Routes>,
        { route },
    );

describe('provider dashboard shell', () => {
    it('shows the business, navigation and who is signed in', async () => {
        setup();
        const nav = await screen.findByRole('navigation', { name: 'Provider' });
        expect(within(nav).getByRole('link', { name: 'Overview' })).toBeInTheDocument();
        expect(within(nav).getByRole('link', { name: 'Settings' })).toBeInTheDocument();
        expect((await screen.findAllByText('Acme Fitness')).length).toBeGreaterThan(0);
        expect(screen.getByText('Olive Owner')).toBeInTheDocument();
    });

    it.each([
        ['pending', /waiting for approval/i],
        ['approved', /approved/i],
        ['active', /live/i],
        ['inactive', /paused/i],
        ['suspended', /paused/i],
    ])('explains a %s account on the overview', async (status, message) => {
        server.use(http.get(url('/provider'), () => HttpResponse.json({ data: { ...provider, status } })));
        setup();

        expect(await screen.findByRole('status', { name: 'Account status' })).toHaveTextContent(message);
    });

    it('shows the rejection reason', async () => {
        server.use(
            http.get(url('/provider'), () => HttpResponse.json({ data: { ...provider, status: 'rejected', rejection_reason: 'Incomplete business details' } })),
        );
        setup();

        expect(await screen.findByRole('status', { name: 'Account status' })).toHaveTextContent('Incomplete business details');
    });

    it('shows the key numbers only once the account is live', async () => {
        setup();
        await screen.findByRole('status', { name: 'Account status' });
        expect(screen.queryByRole('region', { name: 'Key numbers' })).not.toBeInTheDocument();

        cleanup();
        server.use(http.get(url('/provider'), () => HttpResponse.json({ data: { ...provider, status: 'active' } })));
        setup();
        expect(await screen.findByRole('region', { name: 'Key numbers' })).toBeInTheDocument();
    });

    it('shows the storefront address', async () => {
        setup();
        expect(await screen.findByText(/acme\.localhost/)).toBeInTheDocument();
    });
});

describe('FR-P7 provider settings', () => {
    it('loads the current values and saves the changes', async () => {
        let body: unknown;
        server.use(
            http.patch(url('/provider'), async ({ request }) => {
                body = await request.json();
                return HttpResponse.json({ data: { ...provider, name: 'Acme Gym' } });
            }),
        );
        setup('/dashboard/settings');

        const name = await screen.findByLabelText('Business name');
        expect(name).toHaveValue('Acme Fitness');
        expect(screen.getByLabelText('Currency')).toHaveValue('usd');
        await userEvent.clear(name);
        await userEvent.type(name, 'Acme Gym');
        await userEvent.type(screen.getByLabelText('Terms of service URL'), 'https://acme.test/terms');
        await userEvent.click(screen.getByRole('button', { name: 'Save settings' }));

        expect(await screen.findByText('Settings saved.')).toBeInTheDocument();
        expect(body).toMatchObject({
            name: 'Acme Gym',
            support_email: 'help@acme.test',
            timezone: 'UTC',
            currency: 'usd',
            legal: { terms_url: 'https://acme.test/terms' },
        });
    });

    it('does not offer to change the address', async () => {
        setup('/dashboard/settings');
        await screen.findByLabelText('Business name');

        expect(screen.queryByLabelText('Address')).not.toBeInTheDocument();
        expect(screen.getByText(/acme\.localhost/)).toBeInTheDocument();
    });

    it('shows API validation errors next to the field', async () => {
        server.use(
            http.patch(url('/provider'), () =>
                HttpResponse.json({ message: 'invalid', errors: { 'legal.terms_url': ['The terms url must be a valid URL.'] } }, { status: 422 }),
            ),
        );
        setup('/dashboard/settings');

        await userEvent.type(await screen.findByLabelText('Terms of service URL'), 'nonsense');
        await userEvent.click(screen.getByRole('button', { name: 'Save settings' }));

        expect(await screen.findByLabelText('Terms of service URL')).toHaveAccessibleDescription('The terms url must be a valid URL.');
    });
});

describe('FR-PM9 tax setting', () => {
    const withTax = (available: boolean) =>
        server.use(
            http.get(url('/public/config'), () =>
                HttpResponse.json({ data: { platform_name: 'P', default_currency: 'usd', allowed_currencies: ['usd'], tax_available: available } }),
            ),
        );

    it('hides the tax option when the platform has not turned Stripe Tax on', async () => {
        withTax(false);
        setup('/dashboard/settings');
        await screen.findByLabelText('Business name');

        expect(screen.queryByLabelText('Charge tax with Stripe Tax')).not.toBeInTheDocument();
    });

    it('lets the owner switch it on and saves it', async () => {
        withTax(true);
        let body: unknown;
        server.use(
            http.patch(url('/provider'), async ({ request }) => {
                body = await request.json();
                return HttpResponse.json({ data: { ...provider, tax_enabled: true } });
            }),
        );
        setup('/dashboard/settings');

        const toggle = await screen.findByLabelText('Charge tax with Stripe Tax');
        expect(toggle).not.toBeChecked();
        await userEvent.click(toggle);
        await userEvent.click(screen.getByRole('button', { name: 'Save settings' }));

        await waitFor(() => expect(body).toMatchObject({ tax_enabled: true }));
    });
});
