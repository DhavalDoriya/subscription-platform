import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { CommissionRatesPage } from './CommissionRatesPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const base = { created_at: '2026-10-01T00:00:00Z' };
const global = { ...base, id: 'r1', provider_id: null, provider: null, percent: '10.00', effective_from: '2026-10-01T00:00:00Z', in_effect: true };
const special = {
    ...base,
    id: 'r2',
    provider_id: 'p1',
    provider: { id: 'p1', name: 'Acme Fitness', slug: 'acme' },
    percent: '7.50',
    effective_from: '2026-10-01T00:00:00Z',
    in_effect: true,
};
const scheduled = { ...global, id: 'r3', percent: '12.00', effective_from: '2099-01-01T00:00:00Z', in_effect: false };
const requests: URL[] = [];

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    requests.length = 0;
    server.use(
        http.get(url('/admin/commission/rules'), ({ request }) => {
            requests.push(new URL(request.url));
            return HttpResponse.json({ data: [global, special, scheduled], meta: { current_page: 1, per_page: 15, total: 3, last_page: 1 } });
        }),
        http.get(url('/admin/providers'), () =>
            HttpResponse.json({ data: [{ id: 'p1', name: 'Acme Fitness' }], meta: { current_page: 1, per_page: 100, total: 1, last_page: 1 } }),
        ),
    );
});
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe('FR-CM1 FR-CM2 commission rates', () => {
    it('lists the default and provider rates, and says which have not started', async () => {
        renderWithProviders(<CommissionRatesPage />);

        expect(await screen.findAllByText('All providers (default)')).toHaveLength(2);
        expect(screen.getByRole('row', { name: /Acme Fitness/ })).toHaveTextContent('7.50%');
        expect(screen.getByRole('row', { name: /12.00%/ })).toHaveTextContent('Scheduled');
        expect(screen.getByRole('row', { name: /7.50%/ })).toHaveTextContent('Started');
    });

    it('lets an admin remove only a rate that has not started', async () => {
        let removed = false;
        server.use(http.delete(url('/admin/commission/rules/r3'), () => ((removed = true), new HttpResponse(null, { status: 204 }))));
        renderWithProviders(<CommissionRatesPage />);
        await screen.findByText('Acme Fitness');

        expect(within(screen.getByRole('row', { name: /7.50%/ })).queryByRole('button')).not.toBeInTheDocument();
        await userEvent.click(within(screen.getByRole('row', { name: /12.00%/ })).getByRole('button', { name: /Remove/ }));
        await userEvent.click(await screen.findByRole('button', { name: 'Remove rate' }));

        await waitFor(() => expect(removed).toBe(true));
    });

    it('adds a provider override', async () => {
        let body: unknown = null;
        server.use(
            http.post(
                url('/admin/commission/rules'),
                async ({ request }) => ((body = await request.json()), HttpResponse.json({ data: global }, { status: 201 })),
            ),
        );
        renderWithProviders(<CommissionRatesPage />);
        await screen.findByText('Acme Fitness');

        await userEvent.click(screen.getByRole('button', { name: /Add rate/ }));
        const dialog = await screen.findByRole('dialog');
        await userEvent.selectOptions(await within(dialog).findByLabelText(/Provider/), 'p1');
        await userEvent.type(within(dialog).getByLabelText('Commission (%)'), '5');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Add rate' }));

        await waitFor(() => expect(body).toEqual({ percent: '5', provider_id: 'p1' }));
    });

    it('shows the server message when a rate is refused', async () => {
        server.use(
            http.post(url('/admin/commission/rules'), () =>
                HttpResponse.json({ message: 'Invalid.', errors: { percent: ['The percent must not be greater than 100.'] } }, { status: 422 }),
            ),
        );
        renderWithProviders(<CommissionRatesPage />);
        await screen.findByText('Acme Fitness');

        await userEvent.click(screen.getByRole('button', { name: /Add rate/ }));
        const dialog = await screen.findByRole('dialog');
        await userEvent.type(within(dialog).getByLabelText('Commission (%)'), '500');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Add rate' }));

        expect(await within(dialog).findByText('The percent must not be greater than 100.')).toBeInTheDocument();
    });

    it('can show only the default rates', async () => {
        renderWithProviders(<CommissionRatesPage />);
        await screen.findByText('Acme Fitness');

        await userEvent.selectOptions(screen.getByLabelText('Show'), 'global');
        await waitFor(() => expect(requests.at(-1)!.searchParams.get('scope')).toBe('global'));
    });
});
