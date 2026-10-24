import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { Route, Routes } from 'react-router-dom';
import { setAccessTokenGetter, setRefreshHandler } from '@/lib/api';
import { refreshSession, useAuthStore } from '@/lib/auth';
import { renderWithProviders } from '@/test/render';
import { RegisterProviderPage } from './RegisterProviderPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const customer = { id: 'u1', name: 'Olive', email: 'olive@example.com', email_verified_at: null, avatar_url: null, roles: ['customer' as const] };
const owner = { ...customer, roles: ['customer' as const, 'provider_owner' as const] };
const availability: Record<string, { available: boolean; reason: string | null }> = {
    acme: { available: true, reason: null },
    taken: { available: false, reason: 'taken' },
    www: { available: false, reason: 'reserved' },
};
const checked: string[] = [];

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    checked.length = 0;
    setAccessTokenGetter(() => useAuthStore.getState().accessToken);
    setRefreshHandler(refreshSession);
    useAuthStore.getState().setSession(customer, 't');
    server.use(
        http.get(url('/public/config'), () =>
            HttpResponse.json({ data: { platform_name: 'Billing Platform', default_currency: 'usd', allowed_currencies: ['usd', 'eur'] } }),
        ),
        http.get(url('/public/slugs/:slug/availability'), ({ params }) => {
            checked.push(String(params.slug));
            return HttpResponse.json({ data: availability[String(params.slug)] ?? { available: true, reason: null } });
        }),
    );
});
afterEach(() => {
    server.resetHandlers();
    useAuthStore.getState().clear();
});
afterAll(() => server.close());

const setup = () =>
    renderWithProviders(
        <Routes>
            <Route path="/" element={<RegisterProviderPage />} />
            <Route path="/dashboard" element={<h1>Provider home</h1>} />
        </Routes>,
    );

async function fill(slug = 'acme') {
    await userEvent.type(await screen.findByLabelText('Business name'), 'Acme Fitness');
    await userEvent.type(screen.getByLabelText('Address'), slug);
    await userEvent.type(screen.getByLabelText('Category'), 'fitness');
    await userEvent.type(screen.getByLabelText('Country'), 'us');
}

describe('FR-P1 provider registration page', () => {
    it('offers the currencies the admin enabled, defaulting to the platform default', async () => {
        setup();
        const currency = await screen.findByLabelText('Currency');
        await waitFor(() => expect(currency).toBeEnabled()); // enabled once the platform config has loaded
        expect(currency).toHaveValue('usd');
        expect(Array.from((currency as HTMLSelectElement).options).map((o) => o.value)).toEqual(['usd', 'eur']);
    });

    it('prefills the support email from the account', async () => {
        setup();
        expect(await screen.findByLabelText('Support email')).toHaveValue('olive@example.com');
    });

    it('checks the address live, once typing pauses', async () => {
        setup();
        await userEvent.type(await screen.findByLabelText('Address'), 'acme');

        expect(await screen.findByText(/is available/)).toBeInTheDocument();
        expect(checked).toEqual(['acme']);
    });

    it.each([
        ['taken', 'This address is already taken.'],
        ['www', 'This address is reserved.'],
    ])('explains why %s cannot be used', async (slug, message) => {
        setup();
        await userEvent.type(await screen.findByLabelText('Address'), slug);

        expect(await screen.findByText(message)).toBeInTheDocument();
    });

    it('rejects a badly formed address without asking the server', async () => {
        setup();
        await userEvent.type(await screen.findByLabelText('Address'), 'ab');

        expect(await screen.findByText(/3 to 40/)).toBeInTheDocument();
        expect(checked).toEqual([]);
    });

    it('turns capitals and spaces into a valid address as you type', async () => {
        setup();
        const input = await screen.findByLabelText('Address');
        await userEvent.type(input, 'My Gym');

        expect(input).toHaveValue('my-gym');
    });

    it('registers, refreshes the session for the new role and opens the dashboard', async () => {
        let body: unknown;
        server.use(
            http.post(url('/provider/register'), async ({ request }) => {
                body = await request.json();
                return HttpResponse.json({ data: { id: 'p1', slug: 'acme', status: 'approved' } }, { status: 201 });
            }),
            http.post(url('/auth/refresh'), () => HttpResponse.json({ data: { access_token: 'fresh', token_type: 'bearer', expires_in: 900, user: owner } })),
        );
        setup();
        await fill();
        await screen.findByText(/is available/);

        await userEvent.click(screen.getByRole('button', { name: 'Create provider account' }));

        expect(await screen.findByRole('heading', { name: 'Provider home' })).toBeInTheDocument();
        expect(body).toEqual({
            name: 'Acme Fitness',
            slug: 'acme',
            business_category: 'fitness',
            country: 'US',
            support_email: 'olive@example.com',
            currency: 'usd',
        });
        expect(useAuthStore.getState().user?.roles).toContain('provider_owner');
    });

    it('shows API validation errors next to the fields', async () => {
        server.use(
            http.post(url('/provider/register'), () =>
                HttpResponse.json(
                    {
                        message: 'invalid',
                        errors: { slug: ['This address is already taken.'], support_email: ['The support email must be a valid email address.'] },
                    },
                    { status: 422 },
                ),
            ),
        );
        setup();
        await fill();
        await userEvent.click(screen.getByRole('button', { name: 'Create provider account' }));

        await waitFor(() => expect(screen.getByLabelText('Support email')).toHaveAccessibleDescription('The support email must be a valid email address.'));
    });

    it('explains a second registration instead of failing silently', async () => {
        server.use(
            http.post(url('/provider/register'), () =>
                HttpResponse.json({ message: 'You already have a provider account.', code: 'provider_exists' }, { status: 409 }),
            ),
        );
        setup();
        await fill();
        await userEvent.click(screen.getByRole('button', { name: 'Create provider account' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('You already have a provider account.');
    });
});
