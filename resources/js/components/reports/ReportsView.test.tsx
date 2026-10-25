import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { ReportsPage } from '@/areas/admin/ReportsPage';

const download = vi.hoisted(() => vi.fn());
vi.mock('@/lib/download', () => ({ downloadFile: download }));

const url = (p: string) => `http://localhost/api/v1${p}`;
const requests: URL[] = [];

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    requests.length = 0;
    download.mockReset().mockResolvedValue(undefined);
    server.use(
        http.get(url('/admin/reports/revenue'), ({ request }) => {
            requests.push(new URL(request.url));
            return HttpResponse.json({
                data: [{ period: '2026-10', currency: 'usd', payments: 3, gross: 6000, refunded: 1000, net_revenue: 5000, commission: 500 }],
            });
        }),
        http.get(url('/admin/reports/churn'), () => HttpResponse.json({ data: [{ period: '2026-10', new: 4, canceled: 1 }] })),
        http.get(url('/admin/reports/failures'), () => HttpResponse.json({ data: [{ reason: 'insufficient_funds', count: 2, amount: 4000 }] })),
    );
});
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe('FR-AD13 reports', () => {
    it('shows revenue, subscriber changes and failures', async () => {
        renderWithProviders(<ReportsPage />);

        const revenue = await screen.findByRole('table', { name: 'Revenue' });
        expect(within(revenue).getByRole('row', { name: /2026-10/ })).toHaveTextContent('$60.00');
        expect(within(revenue).getByRole('row', { name: /2026-10/ })).toHaveTextContent('$50.00');
        expect(within(revenue).getByRole('row', { name: /2026-10/ })).toHaveTextContent('$5.00');
        expect(within(await screen.findByRole('table', { name: 'Subscribers' })).getByRole('row', { name: /2026-10/ })).toHaveTextContent('4');
        expect(within(await screen.findByRole('table', { name: 'Failed payments' })).getByRole('row', { name: /insufficient funds/ })).toHaveTextContent('2');
    });

    it('sends the date range and grouping with the report requests', async () => {
        renderWithProviders(<ReportsPage />);
        await screen.findByRole('table', { name: 'Revenue' });

        await userEvent.type(screen.getByLabelText('From'), '2026-10-01');
        await userEvent.selectOptions(screen.getByLabelText('Group revenue by'), 'day');

        await waitFor(() => {
            const last = requests.at(-1)!;
            expect(last.searchParams.get('from')).toBe('2026-10-01');
            expect(last.searchParams.get('group')).toBe('day');
        });
    });

    it('downloads a CSV with the chosen filters', async () => {
        renderWithProviders(<ReportsPage />);
        await screen.findByRole('table', { name: 'Revenue' });

        await userEvent.type(screen.getByLabelText('To'), '2026-10-31');
        await userEvent.click(screen.getByRole('button', { name: /Commission CSV/ }));

        await waitFor(() =>
            expect(download).toHaveBeenCalledWith(
                '/admin/reports/export',
                { from: undefined, to: '2026-10-31', type: 'commission', group: 'month' },
                'commission.csv',
            ),
        );
    });

    it('says so when there is nothing to report', async () => {
        server.use(http.get(url('/admin/reports/revenue'), () => HttpResponse.json({ data: [] })));
        renderWithProviders(<ReportsPage />);

        expect(await screen.findByText('No payments in this period.')).toBeInTheDocument();
    });
});
