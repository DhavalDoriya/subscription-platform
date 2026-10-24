import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { InvoicesPage } from './InvoicesPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const base = {
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
    customer: { name: 'Casey', email: 'casey@example.com' },
};
const failed = {
    ...base,
    id: 'i1',
    number: 'INV-1',
    dunning_status: 'retrying',
    first_failed_at: '2026-11-02T09:00:00Z',
    next_retry_at: '2026-11-03T09:00:00Z',
};
const paid = { ...base, id: 'i2', number: 'INV-2', status: 'paid', dunning_status: null, first_failed_at: null, next_retry_at: null };
const requests: URL[] = [];

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    requests.length = 0;
    server.use(
        http.get(url('/provider/invoices'), ({ request }) => {
            requests.push(new URL(request.url));
            const onlyFailed = new URL(request.url).searchParams.get('dunning') === 'active';
            const rows = onlyFailed ? [failed] : [failed, paid];
            return HttpResponse.json({ data: rows, meta: { current_page: 1, per_page: 15, total: rows.length, last_page: 1 } });
        }),
    );
});
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe('FR-R12 provider invoices with payment recovery', () => {
    it('shows where each failed invoice is in the recovery', async () => {
        renderWithProviders(<InvoicesPage />);

        expect(await screen.findByText('INV-1')).toBeInTheDocument();
        expect(screen.getByText('Retrying')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Retry invoice INV-1 now' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Retry invoice INV-2 now' })).not.toBeInTheDocument();
    });

    it('filters to invoices that need attention', async () => {
        renderWithProviders(<InvoicesPage />);
        await screen.findByText('INV-2');

        await userEvent.selectOptions(screen.getByLabelText('Recovery'), 'active');

        await waitFor(() => expect(screen.queryByText('INV-2')).not.toBeInTheDocument());
        expect(requests.at(-1)?.searchParams.get('dunning')).toBe('active');
    });
});
