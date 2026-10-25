import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { CommissionReportPage } from './CommissionReportPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const row = {
    id: 'c1',
    payment_id: 'pay1',
    currency: 'usd',
    gross_amount: 2000,
    refunded_amount: 1000,
    rate_percent: '10.00',
    commission_amount: 200,
    commission_refunded: 100,
    net_commission: 100,
    provider_net: 900,
    created_at: '2026-10-05T00:00:00Z',
    provider: { id: 'p1', name: 'Acme Fitness', slug: 'acme' },
};
const requests: URL[] = [];

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    requests.length = 0;
    server.use(
        http.get(url('/admin/commission/report'), ({ request }) => {
            requests.push(new URL(request.url));
            return HttpResponse.json({
                data: [row],
                meta: { current_page: 1, per_page: 15, total: 1, last_page: 1 },
                totals: [{ currency: 'usd', gross: 2000, refunded: 1000, commission: 200, commission_refunded: 100, net_commission: 100 }],
            });
        }),
        http.get(url('/admin/providers'), () =>
            HttpResponse.json({ data: [{ id: 'p1', name: 'Acme Fitness' }], meta: { current_page: 1, per_page: 100, total: 1, last_page: 1 } }),
        ),
    );
});
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe('FR-CM4 commission report', () => {
    it('shows totals and each payment with gross, fee and what the provider receives', async () => {
        renderWithProviders(<CommissionReportPage />);

        const totals = await screen.findByRole('region', { name: 'Totals in USD' });
        expect(within(totals).getByText(/Commission earned/).parentElement).toHaveTextContent('$2.00');
        expect(within(totals).getByText(/Commission kept/).parentElement).toHaveTextContent('$1.00');

        const line = screen.getByRole('row', { name: /Acme Fitness/ });
        expect(line).toHaveTextContent('$20.00');
        expect(line).toHaveTextContent('$10.00 refunded');
        expect(line).toHaveTextContent('10.00%');
        expect(line).toHaveTextContent('$1.00 given back');
        expect(line).toHaveTextContent('$9.00');
    });

    it('filters by provider and date range', async () => {
        renderWithProviders(<CommissionReportPage />);
        await screen.findByText('Acme Fitness', { selector: 'span.font-medium' });

        await userEvent.selectOptions(await screen.findByLabelText('Provider'), 'p1');
        await waitFor(() => expect(requests.at(-1)!.searchParams.get('provider_id')).toBe('p1'));
        await userEvent.type(screen.getByLabelText('From'), '2026-10-01');
        await waitFor(() => expect(requests.at(-1)!.searchParams.get('from')).toBe('2026-10-01'));
    });
});
