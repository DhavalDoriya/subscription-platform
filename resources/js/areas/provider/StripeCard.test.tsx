import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { Route, Routes } from 'react-router-dom';
import { renderWithProviders } from '@/test/render';
import { StripeCard } from './StripeCard';
import { StripeRefreshPage, StripeReturnPage } from './StripeReturnPages';

const redirectTo = vi.hoisted(() => vi.fn());
vi.mock('@/lib/navigate', () => ({ redirectTo }));

const url = (p: string) => `http://localhost/api/v1${p}`;
const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => redirectTo.mockClear());
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

const card = (stripeStatus: string, providerStatus = 'approved') =>
    renderWithProviders(<StripeCard providerStatus={providerStatus as never} stripeStatus={stripeStatus} />);

describe('FR-P4 Stripe connect card', () => {
    it('invites a new provider to connect and sends them to Stripe', async () => {
        server.use(http.post(url('/provider/stripe/onboarding'), () => HttpResponse.json({ data: { url: 'https://connect.stripe.com/setup/abc' } })));
        card('not_started');

        expect(screen.getByRole('region', { name: 'Payments' })).toHaveTextContent(/connect stripe to accept payments/i);
        await userEvent.click(screen.getByRole('button', { name: 'Connect Stripe' }));

        await waitFor(() => expect(redirectTo).toHaveBeenCalledWith('https://connect.stripe.com/setup/abc'));
    });

    it('holds the button back until the account is approved', () => {
        card('not_started', 'pending');

        expect(screen.getByRole('button', { name: 'Connect Stripe' })).toBeDisabled();
        expect(screen.getByText(/after your account is approved/i)).toBeInTheDocument();
    });

    it('lets a provider resume unfinished onboarding', async () => {
        server.use(http.post(url('/provider/stripe/onboarding'), () => HttpResponse.json({ data: { url: 'https://connect.stripe.com/setup/resume' } })));
        card('pending');

        await userEvent.click(screen.getByRole('button', { name: 'Continue setup' }));

        await waitFor(() => expect(redirectTo).toHaveBeenCalledWith('https://connect.stripe.com/setup/resume'));
    });

    it('says what is wrong with a restricted account and offers both ways forward', () => {
        card('restricted');

        expect(screen.getByRole('region', { name: 'Payments' })).toHaveTextContent(/needs more information/i);
        expect(screen.getByRole('button', { name: 'Fix account' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Open Stripe dashboard' })).toBeInTheDocument();
    });

    it('opens the Express dashboard for an enabled account', async () => {
        server.use(http.post(url('/provider/stripe/dashboard'), () => HttpResponse.json({ data: { url: 'https://dashboard.stripe.com/express/x' } })));
        card('enabled', 'active');

        expect(screen.getByRole('region', { name: 'Payments' })).toHaveTextContent(/payments are enabled/i);
        await userEvent.click(screen.getByRole('button', { name: 'Open Stripe dashboard' }));

        await waitFor(() => expect(redirectTo).toHaveBeenCalledWith('https://dashboard.stripe.com/express/x'));
    });

    it('shows the API message when Stripe is not set up on the platform yet', async () => {
        server.use(
            http.post(url('/provider/stripe/onboarding'), () =>
                HttpResponse.json({ message: 'Stripe is not configured yet.', code: 'stripe_not_configured' }, { status: 503 }),
            ),
        );
        card('not_started');

        await userEvent.click(screen.getByRole('button', { name: 'Connect Stripe' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Stripe is not configured yet.');
        expect(redirectTo).not.toHaveBeenCalled();
    });
});

describe('Stripe return pages', () => {
    it('on return, refreshes the status from Stripe then opens the dashboard', async () => {
        let refreshed = false;
        server.use(
            http.get(
                url('/provider/stripe'),
                ({ request }) => (
                    (refreshed = new URL(request.url).searchParams.get('refresh') === '1'),
                    HttpResponse.json({ data: { status: 'enabled', connected: true } })
                ),
            ),
        );
        renderWithProviders(
            <Routes>
                <Route path="/" element={<StripeReturnPage />} />
                <Route path="/dashboard" element={<h1>Provider home</h1>} />
            </Routes>,
        );

        expect(await screen.findByRole('heading', { name: 'Provider home' })).toBeInTheDocument();
        expect(refreshed).toBe(true);
    });

    it('when the Stripe link expires, makes a fresh one and goes back to Stripe', async () => {
        server.use(http.post(url('/provider/stripe/onboarding'), () => HttpResponse.json({ data: { url: 'https://connect.stripe.com/setup/new' } })));
        renderWithProviders(<StripeRefreshPage />);

        await waitFor(() => expect(redirectTo).toHaveBeenCalledWith('https://connect.stripe.com/setup/new'));
    });

    it('shows a way back when the link cannot be renewed', async () => {
        server.use(
            http.post(url('/provider/stripe/onboarding'), () =>
                HttpResponse.json({ message: 'Your account must be approved before you can connect Stripe.' }, { status: 409 }),
            ),
        );
        renderWithProviders(<StripeRefreshPage />);

        expect(await screen.findByRole('alert')).toHaveTextContent('must be approved');
        expect(screen.getByRole('link', { name: 'Back to dashboard' })).toBeInTheDocument();
    });
});
