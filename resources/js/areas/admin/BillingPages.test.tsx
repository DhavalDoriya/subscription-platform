import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { InvoicesPage } from './InvoicesPage';
import { SubscriptionsPage } from './SubscriptionsPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const meta = (total: number) => ({ current_page: 1, per_page: 15, total, last_page: 1 });
const olive = {
    id: 's1',
    status: 'active',
    has_access: true,
    plan: { id: 'pl1', name: 'Pro' },
    provider: { id: 'p1', name: 'Alpha Gym', slug: 'alpha' },
    customer: { name: 'Olive', email: 'olive@example.com' },
    amount: 1900,
    currency: 'usd',
    interval: 'month',
    interval_count: 1,
    cancel_at_period_end: false,
    current_period_end: '2026-11-20T00:00:00Z',
    trial_ends_at: null,
    canceled_at: null,
    created_at: '2026-10-01T00:00:00Z',
};
const sam = { ...olive, id: 's2', status: 'past_due', customer: { name: 'Sam', email: 'sam@example.com' } };
const ending = { ...olive, id: 's3', customer: { name: 'Kim', email: 'kim@example.com' }, cancel_at_period_end: true };
const canceled = { ...olive, id: 's4', status: 'canceled', customer: { name: 'Gone', email: 'gone@example.com' } };
const requests: URL[] = [];

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    requests.length = 0;
    server.use(
        http.get(url('/admin/subscriptions'), ({ request }) => {
            requests.push(new URL(request.url));
            return HttpResponse.json({ data: [olive, sam, ending, canceled], meta: meta(4) });
        }),
        http.get(url('/admin/invoices'), ({ request }) => {
            requests.push(new URL(request.url));
            return HttpResponse.json({
                data: [
                    {
                        id: 'i1',
                        number: 'ACME-0001',
                        status: 'open',
                        subscription_id: 's1',
                        currency: 'usd',
                        amount_due: 1900,
                        amount_paid: 0,
                        hosted_invoice_url: 'https://pay.stripe.test/i/1',
                        invoice_pdf_url: null,
                        period_start: null,
                        period_end: null,
                        paid_at: null,
                        created_at: '2026-10-02T00:00:00Z',
                        provider: { id: 'p1', name: 'Alpha Gym', slug: 'alpha' },
                        customer: { name: 'Olive', email: 'olive@example.com' },
                    },
                ],
                meta: meta(1),
            });
        }),
    );
});
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

const row = (name: string) => screen.getByRole('row', { name: new RegExp(name) });

describe('FR-AD6 admin subscriptions page', () => {
    it('lists subscriptions across providers with status and price', async () => {
        renderWithProviders(<SubscriptionsPage />);

        expect(await screen.findByRole('cell', { name: /Olive/ })).toBeInTheDocument();
        expect(within(row('Olive')).getByText('Alpha Gym')).toBeInTheDocument();
        expect(within(row('Olive')).getByText('$19.00 / month')).toBeInTheDocument();
        expect(within(row('Olive')).getByText('Active')).toBeInTheDocument();
        expect(within(row('Sam')).getByText('Past due')).toBeInTheDocument();
        expect(within(row('Kim')).getByText('Active · ending')).toBeInTheDocument();
    });

    it('searches and filters by status', async () => {
        renderWithProviders(<SubscriptionsPage />);
        await screen.findByRole('cell', { name: /Olive/ });

        await userEvent.selectOptions(screen.getByLabelText('Status'), 'past_due');
        await waitFor(() => expect(requests.at(-1)!.searchParams.get('status')).toBe('past_due'));

        await userEvent.type(screen.getByRole('searchbox', { name: 'Search subscriptions' }), 'sam');
        await waitFor(() => expect(requests.at(-1)!.searchParams.get('search')).toBe('sam'));
    });

    it('cancels a subscription only after confirmation, naming the customer', async () => {
        let body: unknown = null;
        server.use(
            http.post(
                url('/admin/subscriptions/s1/cancel'),
                async ({ request }) => ((body = await request.json()), HttpResponse.json({ data: { ...olive, status: 'canceled' } })),
            ),
        );
        renderWithProviders(<SubscriptionsPage />);
        await screen.findByRole('cell', { name: /Olive/ });

        await userEvent.click(within(row('Olive')).getByRole('button', { name: /Cancel/ }));
        const confirm = await screen.findByRole('alertdialog');
        expect(confirm).toHaveTextContent('Olive');
        expect(body).toBeNull();
        await userEvent.click(within(confirm).getByRole('button', { name: 'Cancel subscription' }));

        await waitFor(() => expect(body).toEqual({ when: 'now' }));
    });

    it('offers no cancel for a subscription that is already over', async () => {
        renderWithProviders(<SubscriptionsPage />);
        await screen.findByRole('cell', { name: /Gone/ });

        expect(within(row('Gone')).queryByRole('button', { name: /Cancel/ })).not.toBeInTheDocument();
    });
});

describe('FR-AD6 admin invoices page', () => {
    it('lists invoices with their provider, amount and a link to the Stripe-hosted copy', async () => {
        renderWithProviders(<InvoicesPage />);

        expect(await screen.findByRole('cell', { name: 'ACME-0001' })).toBeInTheDocument();
        expect(within(row('ACME-0001')).getByText('Alpha Gym')).toBeInTheDocument();
        expect(within(row('ACME-0001')).getByText('$19.00')).toBeInTheDocument();
        expect(within(row('ACME-0001')).getByText('Open')).toBeInTheDocument();
        expect(within(row('ACME-0001')).getByRole('link', { name: 'Open invoice ACME-0001' })).toHaveAttribute('href', 'https://pay.stripe.test/i/1');
    });

    it('filters by status', async () => {
        renderWithProviders(<InvoicesPage />);
        await screen.findByRole('cell', { name: 'ACME-0001' });

        await userEvent.selectOptions(screen.getByLabelText('Status'), 'paid');
        await waitFor(() => expect(requests.at(-1)!.searchParams.get('status')).toBe('paid'));
    });
});

describe('FR-R9 admin invoices with payment recovery', () => {
    it('lets an admin retry a failed invoice and filter to failed ones', async () => {
        const failedInvoice = {
            id: 'i9',
            number: 'INV-9',
            status: 'open',
            subscription_id: 's2',
            currency: 'usd',
            amount_due: 1900,
            amount_paid: 0,
            hosted_invoice_url: null,
            invoice_pdf_url: null,
            period_start: null,
            period_end: null,
            paid_at: null,
            created_at: '2026-11-01T00:00:00Z',
            provider: { id: 'p1', name: 'Alpha Gym', slug: 'alpha' },
            customer: { name: 'Sam', email: 'sam@example.com' },
            dunning_status: 'awaiting_customer',
            first_failed_at: '2026-11-02T09:00:00Z',
            next_retry_at: null,
        };
        let retried = false;
        server.use(
            http.get(url('/admin/invoices'), ({ request }) => {
                requests.push(new URL(request.url));
                return HttpResponse.json({ data: [failedInvoice], meta: meta(1) });
            }),
            http.post(url('/admin/invoices/i9/retry'), () => ((retried = true), HttpResponse.json({ data: failedInvoice }))),
        );
        renderWithProviders(<InvoicesPage />);

        expect(await screen.findByText('Waiting for customer')).toBeInTheDocument();
        await userEvent.selectOptions(screen.getByLabelText('Recovery'), 'active');
        await waitFor(() => expect(requests.at(-1)?.searchParams.get('dunning')).toBe('active'));

        await userEvent.click(screen.getByRole('button', { name: 'Retry invoice INV-9 now' }));
        await waitFor(() => expect(retried).toBe(true));
    });
});
