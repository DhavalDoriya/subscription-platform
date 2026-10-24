import { screen, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { useAuthStore } from '@/lib/auth';
import { FixPaymentPage } from './FixPaymentPage';
import { PaymentIssues } from './PaymentIssues';

const url = (p: string) => `http://localhost/api/v1${p}`;
const issue = (overrides: object = {}) => ({
    invoice_id: 'i1',
    subscription_id: 's1',
    subscription_status: 'past_due',
    amount_due: 2000,
    currency: 'usd',
    dunning_status: 'retrying',
    decline_type: 'soft',
    failure_message: 'Your card has insufficient funds.',
    next_retry_at: '2026-11-03T09:00:00Z',
    grace_ends_at: null,
    hosted_invoice_url: 'https://pay.stripe.test/i1',
    plan: { name: 'Gold' },
    provider: { id: 'p1', name: 'Alpha Gym', slug: 'alpha' },
    ...overrides,
});

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    useAuthStore
        .getState()
        .setSession({ id: 'u1', name: 'Casey', email: 'c@example.com', email_verified_at: null, avatar_url: null, roles: ['customer'] } as never, 't');
    server.use(
        http.get(url('/customer/payment-methods'), () => HttpResponse.json({ data: [] })),
        http.get(url('/customer/subscriptions'), () => HttpResponse.json({ data: [], meta: { current_page: 1, per_page: 100, total: 0, last_page: 1 } })),
    );
});
afterEach(() => {
    server.resetHandlers();
    useAuthStore.getState().clear();
});
afterAll(() => server.close());

describe('FR-R3 payment problems banner', () => {
    it('warns about a failed payment with a link to fix it', async () => {
        server.use(http.get(url('/customer/payment-issues'), () => HttpResponse.json({ data: [issue()] })));
        renderWithProviders(<PaymentIssues />);

        const banner = await screen.findByRole('region', { name: 'Payment problems' });
        expect(within(banner).getByText(/gold with alpha gym/i)).toBeInTheDocument();
        expect(within(banner).getByText(/\$20\.00/)).toBeInTheDocument();
        expect(within(banner).getByRole('link', { name: 'Fix payment' })).toHaveAttribute('href', '/account/fix-payment');
    });

    it('renders nothing when every payment is fine', async () => {
        server.use(http.get(url('/customer/payment-issues'), () => HttpResponse.json({ data: [] })));
        const { container } = renderWithProviders(<PaymentIssues />);

        await new Promise((r) => setTimeout(r, 50));
        expect(container).toBeEmptyDOMElement();
    });
});

describe('FR-R3 fix payment page', () => {
    it('explains a temporary decline and when we will try again', async () => {
        server.use(http.get(url('/customer/payment-issues'), () => HttpResponse.json({ data: [issue()] })));
        renderWithProviders(<FixPaymentPage />);

        expect(await screen.findByRole('heading', { name: 'Fix your payment' })).toBeInTheDocument();
        expect(await screen.findByText(/we.ll try again on nov 3, 2026/i)).toBeInTheDocument();
        expect(screen.getByText('Your card has insufficient funds.')).toBeInTheDocument();
        expect(screen.getByRole('region', { name: 'Payment methods' })).toBeInTheDocument();
    });

    it('asks for a new card after a permanent decline and says how long access lasts', async () => {
        server.use(
            http.get(url('/customer/payment-issues'), () =>
                HttpResponse.json({
                    data: [
                        issue({
                            decline_type: 'hard',
                            dunning_status: 'awaiting_customer',
                            subscription_status: 'grace',
                            next_retry_at: null,
                            grace_ends_at: '2026-11-09T09:00:00Z',
                        }),
                    ],
                }),
            ),
        );
        renderWithProviders(<FixPaymentPage />);

        expect(await screen.findByText(/can.t be used again/i)).toBeInTheDocument();
        expect(screen.getByText(/access continues until nov 9, 2026/i)).toBeInTheDocument();
    });

    it('sends a customer whose bank wants confirmation to the secure payment page', async () => {
        server.use(
            http.get(url('/customer/payment-issues'), () =>
                HttpResponse.json({ data: [issue({ decline_type: 'auth_required', dunning_status: 'awaiting_customer', next_retry_at: null })] }),
            ),
        );
        renderWithProviders(<FixPaymentPage />);

        const link = await screen.findByRole('link', { name: /confirm payment/i });
        expect(link).toHaveAttribute('href', 'https://pay.stripe.test/i1');
        expect(link).toHaveAttribute('rel', expect.stringContaining('noopener'));
    });

    it('says everything is fine when there is nothing to fix', async () => {
        server.use(http.get(url('/customer/payment-issues'), () => HttpResponse.json({ data: [] })));
        renderWithProviders(<FixPaymentPage />);

        expect(await screen.findByText(/nothing to fix/i)).toBeInTheDocument();
    });
});
