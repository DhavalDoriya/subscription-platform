import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import type { Invoice } from '@/lib/billing';
import { renderWithProviders } from '@/test/render';
import { InvoiceRecoveryActions, RecoveryCell } from './Recovery';

const url = (p: string) => `http://localhost/api/v1${p}`;
const invoice = (overrides: Partial<Invoice> = {}): Invoice => ({
    id: 'i1',
    number: 'INV-1',
    status: 'open',
    subscription_id: 's1',
    currency: 'usd',
    amount_due: 2000,
    amount_paid: 0,
    hosted_invoice_url: null,
    invoice_pdf_url: null,
    period_start: null,
    period_end: null,
    paid_at: null,
    created_at: '2026-11-01T00:00:00Z',
    dunning_status: 'retrying',
    first_failed_at: '2026-11-02T09:00:00Z',
    next_retry_at: '2026-11-03T09:00:00Z',
    ...overrides,
});

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe('FR-R12 recovery cell', () => {
    it('shows the state and when the next retry runs', () => {
        renderWithProviders(<RecoveryCell invoice={invoice()} />);

        expect(screen.getByText('Retrying')).toBeInTheDocument();
        expect(screen.getByText(/next retry nov 3, 2026/i)).toBeInTheDocument();
    });

    it.each([
        ['awaiting_customer', 'Waiting for customer'],
        ['exhausted', 'Retries used up'],
        ['recovered', 'Recovered'],
        ['resolved', 'Resolved'],
    ] as const)('labels %s', (status, label) => {
        renderWithProviders(<RecoveryCell invoice={invoice({ dunning_status: status, next_retry_at: null })} />);

        expect(screen.getByText(label)).toBeInTheDocument();
        expect(screen.queryByText(/next retry/i)).not.toBeInTheDocument();
    });

    it('shows nothing for an invoice that never failed', () => {
        renderWithProviders(<RecoveryCell invoice={invoice({ dunning_status: null, next_retry_at: null, first_failed_at: null })} />);

        expect(screen.getByText('—')).toBeInTheDocument();
    });
});

describe('FR-R9 recovery actions', () => {
    it('retries now and reports the outcome', async () => {
        let called = false;
        server.use(
            http.post(url('/provider/invoices/i1/retry'), () => {
                called = true;
                return HttpResponse.json({ data: invoice({ status: 'paid', dunning_status: 'recovered' }) });
            }),
        );
        renderWithProviders(<InvoiceRecoveryActions invoice={invoice()} basePath="/provider" />);

        await userEvent.click(screen.getByRole('button', { name: 'Retry invoice INV-1 now' }));

        await waitFor(() => expect(called).toBe(true));
    });

    it('uses the admin path for admins', async () => {
        let called = false;
        server.use(
            http.post(url('/admin/invoices/i1/retry'), () => {
                called = true;
                return HttpResponse.json({ data: invoice() });
            }),
        );
        renderWithProviders(<InvoiceRecoveryActions invoice={invoice()} basePath="/admin" />);

        await userEvent.click(screen.getByRole('button', { name: 'Retry invoice INV-1 now' }));

        await waitFor(() => expect(called).toBe(true));
    });

    it('asks before marking resolved', async () => {
        let called = false;
        server.use(
            http.post(url('/provider/invoices/i1/resolve'), () => {
                called = true;
                return HttpResponse.json({ data: invoice({ dunning_status: 'resolved' }) });
            }),
        );
        renderWithProviders(<InvoiceRecoveryActions invoice={invoice()} basePath="/provider" />);

        await userEvent.click(screen.getByRole('button', { name: 'Mark invoice INV-1 resolved' }));
        expect(called).toBe(false);
        await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Mark resolved' }));

        await waitFor(() => expect(called).toBe(true));
    });

    it('shows the attempts and notices of an invoice', async () => {
        server.use(
            http.get(url('/provider/invoices/i1/dunning'), () =>
                HttpResponse.json({
                    data: {
                        attempts: [
                            {
                                id: 'a1',
                                attempt_number: 1,
                                kind: 'initial',
                                status: 'failed',
                                decline_code: 'insufficient_funds',
                                decline_type: 'soft',
                                failure_message: 'Your card has insufficient funds.',
                                attempted_at: '2026-11-02T09:00:00Z',
                                next_retry_at: '2026-11-03T09:00:00Z',
                            },
                        ],
                        events: [
                            { id: 'e1', type: 'failure_notice', template: 'dunning.payment_failed', occurred_at: '2026-11-02T09:00:00Z' },
                            { id: 'e2', type: 'retry_scheduled', template: null, occurred_at: '2026-11-02T09:00:00Z' },
                        ],
                    },
                }),
            ),
        );
        renderWithProviders(<InvoiceRecoveryActions invoice={invoice()} basePath="/provider" />);

        await userEvent.click(screen.getByRole('button', { name: 'Show recovery history for INV-1' }));

        const dialog = await screen.findByRole('dialog');
        expect(await within(dialog).findByText('Attempt 1')).toBeInTheDocument();
        expect(within(dialog).getByText(/insufficient_funds/)).toBeInTheDocument();
        expect(within(dialog).getByText('Failure notice sent')).toBeInTheDocument();
        expect(within(dialog).getByText('Retry scheduled')).toBeInTheDocument();
    });

    it('offers only the history once the payment is recovered', () => {
        renderWithProviders(<InvoiceRecoveryActions invoice={invoice({ dunning_status: 'recovered', status: 'paid' })} basePath="/provider" />);

        expect(screen.queryByRole('button', { name: /retry invoice/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /mark invoice/i })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Show recovery history for INV-1' })).toBeInTheDocument();
    });

    it('offers nothing for an invoice that never failed', () => {
        renderWithProviders(<InvoiceRecoveryActions invoice={invoice({ dunning_status: null })} basePath="/provider" />);

        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
});
