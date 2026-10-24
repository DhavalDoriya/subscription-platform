import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { SubscribersPage } from './SubscribersPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const meta = (total: number) => ({ current_page: 1, per_page: 15, total, last_page: 1 });
const sub = {
    id: 's1',
    status: 'active',
    has_access: true,
    plan: { id: 'pl1', name: 'Pro' },
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
const sam = { ...sub, id: 's2', status: 'past_due', customer: { name: 'Sam', email: 'sam@example.com' } };
const detail = {
    ...sub,
    invoices: [
        {
            id: 'i1',
            number: 'ACME-0001',
            status: 'paid',
            currency: 'usd',
            amount_due: 1900,
            amount_paid: 1900,
            hosted_invoice_url: 'https://pay.stripe.test/i/1',
            invoice_pdf_url: null,
            paid_at: '2026-10-01T00:00:00Z',
            created_at: '2026-10-01T00:00:00Z',
        },
    ],
    payments: [
        {
            id: 'pay1',
            status: 'succeeded',
            amount: 1900,
            amount_refunded: 0,
            refundable: 1900,
            currency: 'usd',
            failure_code: null,
            failure_message: null,
            paid_at: '2026-10-01T00:00:00Z',
            created_at: '2026-10-01T00:00:00Z',
            refunds: [],
        },
        {
            id: 'pay2',
            status: 'failed',
            amount: 1900,
            amount_refunded: 0,
            refundable: 1900,
            currency: 'usd',
            failure_code: 'insufficient_funds',
            failure_message: 'Your card has insufficient funds.',
            paid_at: null,
            created_at: '2026-09-01T00:00:00Z',
            refunds: [],
        },
    ],
};
const requests: URL[] = [];

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    requests.length = 0;
    server.use(
        http.get(url('/provider/subscriptions'), ({ request }) => {
            requests.push(new URL(request.url));
            return HttpResponse.json({ data: [sub, sam], meta: meta(2) });
        }),
        http.get(url('/provider/subscriptions/s1'), () => HttpResponse.json({ data: detail })),
    );
});
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

const row = (name: string) => screen.getByRole('row', { name: new RegExp(name) });

describe('FR-PD3 provider subscribers page', () => {
    it('lists real subscribers with plan, status and renewal date', async () => {
        renderWithProviders(<SubscribersPage />);

        expect(await screen.findByRole('cell', { name: /Olive/ })).toBeInTheDocument();
        expect(within(row('Olive')).getByText('olive@example.com')).toBeInTheDocument();
        expect(within(row('Olive')).getByText('Pro')).toBeInTheDocument();
        expect(within(row('Olive')).getByText('Active')).toBeInTheDocument();
        expect(within(row('Sam')).getByText('Past due')).toBeInTheDocument();
    });

    it('searches and filters by status', async () => {
        renderWithProviders(<SubscribersPage />);
        await screen.findByRole('cell', { name: /Olive/ });

        await userEvent.selectOptions(screen.getByLabelText('Status'), 'past_due');
        await waitFor(() => expect(requests.at(-1)!.searchParams.get('status')).toBe('past_due'));
        await userEvent.type(screen.getByRole('searchbox', { name: 'Search subscribers' }), 'sam');
        await waitFor(() => expect(requests.at(-1)!.searchParams.get('search')).toBe('sam'));
    });

    it('opens a subscriber to show invoices and payments, including why a payment failed', async () => {
        renderWithProviders(<SubscribersPage />);
        await userEvent.click(await screen.findByRole('button', { name: 'View Olive' }));

        const dialog = await screen.findByRole('dialog');
        expect(await within(dialog).findByText('ACME-0001')).toBeInTheDocument();
        expect(within(dialog).getByText(/insufficient funds/i)).toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: 'Refund $19.00' })).toBeInTheDocument(); // only the paid payment
        expect(within(dialog).getAllByRole('button', { name: /Refund/ })).toHaveLength(1);
    });

    it('cancels at the period end from the subscriber dialog', async () => {
        let body: unknown = null;
        server.use(
            http.post(
                url('/provider/subscriptions/s1/cancel'),
                async ({ request }) => ((body = await request.json()), HttpResponse.json({ data: { ...sub, cancel_at_period_end: true } })),
            ),
        );
        renderWithProviders(<SubscribersPage />);
        await userEvent.click(await screen.findByRole('button', { name: 'View Olive' }));
        const dialog = await screen.findByRole('dialog');
        await within(dialog).findByText('ACME-0001');

        await userEvent.click(within(dialog).getByRole('button', { name: 'Cancel at period end' }));
        await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Cancel at period end' }));

        await waitFor(() => expect(body).toEqual({ when: 'period_end' }));
    });

    it('refunds part of a payment from the dialog', async () => {
        let body: unknown = null;
        server.use(
            http.post(
                url('/provider/payments/pay1/refund'),
                async ({ request }) => ((body = await request.json()), HttpResponse.json({ data: { id: 'r1', amount: 500 } }, { status: 201 })),
            ),
        );
        renderWithProviders(<SubscribersPage />);
        await userEvent.click(await screen.findByRole('button', { name: 'View Olive' }));
        const dialog = await screen.findByRole('dialog');
        await userEvent.click(await within(dialog).findByRole('button', { name: 'Refund $19.00' }));

        const refund = await screen.findByRole('dialog', { name: /Refund/ });
        const amount = within(refund).getByLabelText('Amount');
        expect(amount).toHaveValue('19.00');
        await userEvent.clear(amount);
        await userEvent.type(amount, '5.00');
        await userEvent.click(within(refund).getByRole('button', { name: 'Refund' }));

        await waitFor(() => expect(body).toEqual({ amount: 500, reason: null }));
    });

    it('refuses a refund larger than what is left, without calling the server', async () => {
        let called = false;
        server.use(http.post(url('/provider/payments/pay1/refund'), () => ((called = true), HttpResponse.json({}, { status: 201 }))));
        renderWithProviders(<SubscribersPage />);
        await userEvent.click(await screen.findByRole('button', { name: 'View Olive' }));
        await userEvent.click(await within(await screen.findByRole('dialog')).findByRole('button', { name: 'Refund $19.00' }));

        const refund = await screen.findByRole('dialog', { name: /Refund/ });
        const amount = within(refund).getByLabelText('Amount');
        await userEvent.clear(amount);
        await userEvent.type(amount, '25.00');
        await userEvent.click(within(refund).getByRole('button', { name: 'Refund' }));

        expect(await within(refund).findByText(/up to \$19\.00/)).toBeInTheDocument();
        expect(called).toBe(false);
    });
});
