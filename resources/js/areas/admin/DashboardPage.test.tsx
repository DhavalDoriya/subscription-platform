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

    it('shows the API error', async () => {
        server.use(http.get('http://localhost/api/v1/admin/dashboard', () => HttpResponse.json({ message: 'Server error.' }, { status: 500 })));
        renderWithProviders(<DashboardPage />);

        expect(await screen.findByRole('alert')).toHaveTextContent('Server error.');
    });
});
