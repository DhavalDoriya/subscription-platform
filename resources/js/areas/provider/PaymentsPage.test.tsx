import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { InvoicesPage } from './InvoicesPage';
import { PaymentsPage } from './PaymentsPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const meta = (total: number) => ({ current_page: 1, per_page: 15, total, last_page: 1 });
const paid = {
    id: 'pay1',
    subscription_id: 's1',
    invoice_id: null,
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
};
const partly = {
    ...paid,
    id: 'pay2',
    status: 'partially_refunded',
    amount_refunded: 400,
    refundable: 1500,
    commission: { rate_percent: '10.00', amount: 190, refunded: 40, net_fee: 150, provider_net: 1350 },
};
const failed = { ...paid, id: 'pay3', status: 'failed', refundable: 1900, failure_code: 'insufficient_funds' };
const requests: URL[] = [];

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    requests.length = 0;
    server.use(
        http.get(url('/provider/payments'), ({ request }) => {
            requests.push(new URL(request.url));
            return HttpResponse.json({ data: [paid, partly, failed], meta: meta(3) });
        }),
        http.get(url('/provider/invoices'), ({ request }) => {
            requests.push(new URL(request.url));
            return HttpResponse.json({
                data: [
                    {
                        id: 'i1',
                        number: 'ACME-0001',
                        status: 'paid',
                        subscription_id: 's1',
                        currency: 'usd',
                        amount_due: 1900,
                        amount_paid: 1900,
                        hosted_invoice_url: 'https://pay.stripe.test/i/1',
                        invoice_pdf_url: null,
                        period_start: null,
                        period_end: null,
                        paid_at: null,
                        created_at: '2026-10-02T00:00:00Z',
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

describe('FR-PD4 provider payments page', () => {
    it('lists payments with refunded amounts and the failure reason', async () => {
        renderWithProviders(<PaymentsPage />);
        await screen.findAllByRole('row', { name: /Paid/ });

        expect(screen.getByRole('row', { name: /Partly refunded/ })).toHaveTextContent('$4.00 refunded');
        expect(screen.getByRole('row', { name: /Failed/ })).toHaveTextContent('insufficient_funds');
    });

    it('FR-CM4 shows the platform fee and what the provider receives, with and without a fee', async () => {
        renderWithProviders(<PaymentsPage />);
        await screen.findAllByRole('row', { name: /Paid/ });

        const withFee = screen.getByRole('row', { name: /Partly refunded/ });
        expect(withFee).toHaveTextContent('$1.50');
        expect(withFee).toHaveTextContent('10.00%');
        expect(withFee).toHaveTextContent('$13.50');
        expect(screen.getByRole('row', { name: /Failed/ })).not.toHaveTextContent('$19.00 ');
        expect(within(screen.getByRole('row', { name: /^.*Paid/ })).getAllByText('—').length).toBeGreaterThan(0);
    });

    it('FR-CM3 flags a payment that is being disputed, with the deadline to respond', async () => {
        server.use(
            http.get(url('/provider/payments'), () =>
                HttpResponse.json({
                    data: [{ ...paid, dispute: { status: 'needs_response', amount: 1900, reason: 'fraudulent', evidence_due_by: '2026-10-20T00:00:00Z' } }],
                    meta: meta(1),
                }),
            ),
        );
        renderWithProviders(<PaymentsPage />);

        expect(await screen.findByText(/Dispute: respond by the deadline/)).toHaveTextContent('by Oct 20, 2026');
    });

    it('offers a refund only where something can still be refunded', async () => {
        renderWithProviders(<PaymentsPage />);
        await screen.findAllByRole('row', { name: /Paid/ });

        expect(screen.getAllByRole('button', { name: /^Refund/ })).toHaveLength(2); // paid and partly refunded, not failed
        expect(within(screen.getByRole('row', { name: /Failed/ })).queryByRole('button')).not.toBeInTheDocument();
    });

    it('refunds the remaining amount of a partly refunded payment', async () => {
        let body: unknown = null;
        server.use(
            http.post(
                url('/provider/payments/pay2/refund'),
                async ({ request }) => ((body = await request.json()), HttpResponse.json({ data: { id: 'r1' } }, { status: 201 })),
            ),
        );
        renderWithProviders(<PaymentsPage />);
        await screen.findAllByRole('row', { name: /Paid/ });

        await userEvent.click(within(screen.getByRole('row', { name: /Partly refunded/ })).getByRole('button', { name: 'Refund $15.00' }));
        const dialog = await screen.findByRole('dialog', { name: /Refund/ });
        expect(within(dialog).getByLabelText('Amount')).toHaveValue('15.00');
        await userEvent.selectOptions(within(dialog).getByLabelText('Reason'), 'duplicate');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Refund' }));

        await waitFor(() => expect(body).toEqual({ amount: 1500, reason: 'duplicate' }));
    });

    it('filters by status', async () => {
        renderWithProviders(<PaymentsPage />);
        await screen.findAllByRole('row', { name: /Paid/ });

        await userEvent.selectOptions(screen.getByLabelText('Status'), 'failed');
        await waitFor(() => expect(requests.at(-1)!.searchParams.get('status')).toBe('failed'));
    });
});

describe('FR-PD4 provider invoices page', () => {
    it('lists invoices with a link to the Stripe-hosted copy', async () => {
        renderWithProviders(<InvoicesPage />);

        expect(await screen.findByRole('cell', { name: 'ACME-0001' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open invoice ACME-0001' })).toHaveAttribute('href', 'https://pay.stripe.test/i/1');
    });
});
