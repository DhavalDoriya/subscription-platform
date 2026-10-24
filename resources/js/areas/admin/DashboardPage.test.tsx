import { screen, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { DashboardPage } from './DashboardPage';

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe('FR-AD1 dashboard', () => {
    it('shows provider counts by status, customers and recent signups', async () => {
        server.use(
            http.get('http://localhost/api/v1/admin/dashboard', () =>
                HttpResponse.json({
                    data: {
                        providers: { total: 7, by_status: { pending: 2, approved: 1, active: 3, inactive: 0, suspended: 1, rejected: 0 } },
                        customers: { total: 41 },
                        dunning: {
                            open_invoices: 4,
                            open_amount: { usd: 8000, eur: 1500 },
                            at_risk_mrr: { usd: 6000 },
                            recovered_30d: 3,
                            lost_30d: 1,
                            recovery_rate: 75,
                        },
                        recent_providers: [{ id: 'p1', name: 'Acme Fitness', slug: 'acme', status: 'pending', created_at: '2026-10-01T10:00:00Z' }],
                        recent_customers: [{ id: 'c1', name: 'Ann Lee', email: 'ann@example.com', created_at: '2026-10-02T10:00:00Z' }],
                    },
                }),
            ),
        );
        renderWithProviders(<DashboardPage />);

        const providers = await screen.findByRole('region', { name: 'Providers' });
        expect(within(providers).getByText('7')).toBeInTheDocument();
        expect(within(providers).getByText('Pending')).toBeInTheDocument();
        expect(within(screen.getByRole('region', { name: 'Customers' })).getByText('41')).toBeInTheDocument();
        expect(screen.getByText('Acme Fitness')).toBeInTheDocument();
        expect(screen.getByText('ann@example.com')).toBeInTheDocument();
    });

    it('FR-R12 shows failed payments, revenue at risk per currency and the recovery rate', async () => {
        server.use(
            http.get('http://localhost/api/v1/admin/dashboard', () =>
                HttpResponse.json({
                    data: {
                        providers: { total: 0, by_status: { pending: 0, approved: 0, active: 0, inactive: 0, suspended: 0, rejected: 0 } },
                        customers: { total: 0 },
                        dunning: {
                            open_invoices: 4,
                            open_amount: { usd: 8000, eur: 1500 },
                            at_risk_mrr: { usd: 6000 },
                            recovered_30d: 3,
                            lost_30d: 1,
                            recovery_rate: 75,
                        },
                        recent_providers: [],
                        recent_customers: [],
                    },
                }),
            ),
        );
        renderWithProviders(<DashboardPage />);

        const recovery = await screen.findByRole('region', { name: 'Payment recovery' });
        expect(within(recovery).getByText('Failed invoices').parentElement).toHaveTextContent('4');
        expect(within(recovery).getByText('Recovery rate (30 days)').parentElement).toHaveTextContent('75%');
        expect(within(recovery).getByText('Amount outstanding').parentElement).toHaveTextContent('$80.00');
        expect(within(recovery).getByText('Amount outstanding').parentElement).toHaveTextContent('€15.00');
        expect(within(recovery).getByText('Monthly revenue at risk').parentElement).toHaveTextContent('$60.00');
    });

    it('shows the API error', async () => {
        server.use(http.get('http://localhost/api/v1/admin/dashboard', () => HttpResponse.json({ message: 'Server error.' }, { status: 500 })));
        renderWithProviders(<DashboardPage />);

        expect(await screen.findByRole('alert')).toHaveTextContent('Server error.');
    });
});
