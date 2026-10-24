import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { PlansAdminPage } from './PlansAdminPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const price = { id: 'pr1', amount: 1900, currency: 'usd', interval: 'month', interval_count: 1, status: 'active', synced: true };
const gold = {
    id: 'pl1',
    name: 'Gold',
    description: null,
    features: [],
    status: 'active',
    visibility: 'public',
    trial_days: 0,
    sort_order: 0,
    prices: [price],
    created_at: '2026-10-01T00:00:00Z',
    provider: { id: 'p1', name: 'Alpha Gym', slug: 'alpha' },
};
const silver = { ...gold, id: 'pl2', name: 'Silver', status: 'draft', prices: [], provider: { id: 'p2', name: 'Beta Yoga', slug: 'beta' } };
const requests: URL[] = [];

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    requests.length = 0;
    server.use(
        http.get(url('/admin/plans'), ({ request }) => {
            requests.push(new URL(request.url));
            return HttpResponse.json({ data: [gold, silver], meta: { current_page: 1, per_page: 15, total: 2, last_page: 1 } });
        }),
    );
});
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

const row = (name: string) => screen.getByRole('row', { name: new RegExp(name) });

describe('FR-AD5 admin plans page', () => {
    it('lists plans of every provider with their owner and price', async () => {
        renderWithProviders(<PlansAdminPage />);

        expect(await screen.findByRole('cell', { name: /Gold/ })).toBeInTheDocument();
        expect(within(row('Gold')).getByText('Alpha Gym')).toBeInTheDocument();
        expect(within(row('Gold')).getByText('$19.00 / month')).toBeInTheDocument();
        expect(within(row('Silver')).getByText('Draft')).toBeInTheDocument();
    });

    it('searches and filters by status', async () => {
        renderWithProviders(<PlansAdminPage />);
        await screen.findByRole('cell', { name: /Gold/ });

        await userEvent.selectOptions(screen.getByLabelText('Status'), 'draft');
        await waitFor(() => expect(requests.at(-1)!.searchParams.get('status')).toBe('draft'));

        await userEvent.type(screen.getByRole('searchbox', { name: 'Search plans' }), 'gol');
        await waitFor(() => expect(requests.at(-1)!.searchParams.get('search')).toBe('gol'));
    });

    it('force-archives a plan after confirmation, naming the provider', async () => {
        let archived = false;
        server.use(http.post(url('/admin/plans/pl1/archive'), () => ((archived = true), HttpResponse.json({ data: { ...gold, status: 'archived' } }))));
        renderWithProviders(<PlansAdminPage />);
        await screen.findByRole('cell', { name: /Gold/ });

        await userEvent.click(within(row('Gold')).getByRole('button', { name: /Archive/ }));
        const confirm = await screen.findByRole('alertdialog');
        expect(confirm).toHaveTextContent('Alpha Gym');
        expect(archived).toBe(false);
        await userEvent.click(within(confirm).getByRole('button', { name: 'Archive' }));

        await waitFor(() => expect(archived).toBe(true));
    });

    it('offers no archive for a plan that is already archived', async () => {
        server.use(
            http.get(url('/admin/plans'), () =>
                HttpResponse.json({ data: [{ ...gold, status: 'archived' }], meta: { current_page: 1, per_page: 15, total: 1, last_page: 1 } }),
            ),
        );
        renderWithProviders(<PlansAdminPage />);
        await screen.findByRole('cell', { name: /Gold/ });

        expect(screen.queryByRole('button', { name: /Archive/ })).not.toBeInTheDocument();
    });
});
