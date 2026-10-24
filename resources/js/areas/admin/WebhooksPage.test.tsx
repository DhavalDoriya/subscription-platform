import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { WebhooksPage } from './WebhooksPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const ok = {
    id: 'e1',
    stripe_event_id: 'evt_ok',
    type: 'account.updated',
    account_id: 'acct_1',
    livemode: false,
    status: 'processed',
    attempts: 1,
    error: null,
    processed_at: '2026-10-02T10:00:00Z',
    created_at: '2026-10-02T10:00:00Z',
};
const bad = { ...ok, id: 'e2', stripe_event_id: 'evt_bad', type: 'invoice.paid', status: 'failed', attempts: 2, error: 'database hiccup' };
const requests: URL[] = [];

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    requests.length = 0;
    server.use(
        http.get(url('/admin/webhook-events'), ({ request }) => {
            requests.push(new URL(request.url));
            return HttpResponse.json({ data: [ok, bad], meta: { current_page: 1, per_page: 15, total: 2, last_page: 1 } });
        }),
    );
});
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

const row = (id: string) => screen.getByRole('row', { name: new RegExp(id) });

describe('FR-AD11 webhook monitor', () => {
    it('lists events with their status, attempts and any error', async () => {
        renderWithProviders(<WebhooksPage />);

        expect(await screen.findByText('evt_ok')).toBeInTheDocument();
        expect(within(row('evt_ok')).getByText('Processed')).toBeInTheDocument();
        expect(within(row('evt_bad')).getByText('Failed')).toBeInTheDocument();
        expect(within(row('evt_bad')).getByText('database hiccup')).toBeInTheDocument();
        expect(within(row('evt_bad')).getByText('2')).toBeInTheDocument();
    });

    it('filters by status and searches by type or id', async () => {
        renderWithProviders(<WebhooksPage />);
        await screen.findByText('evt_ok');

        await userEvent.selectOptions(screen.getByLabelText('Status'), 'failed');
        await waitFor(() => expect(requests.at(-1)!.searchParams.get('status')).toBe('failed'));

        await userEvent.type(screen.getByRole('searchbox', { name: 'Search events' }), 'invoice');
        await waitFor(() => expect(requests.at(-1)!.searchParams.get('search')).toBe('invoice'));
    });

    it('shows the payload of an event', async () => {
        server.use(
            http.get(url('/admin/webhook-events/e1'), () =>
                HttpResponse.json({ data: { ...ok, payload: { id: 'evt_ok', type: 'account.updated', data: { object: { id: 'acct_1' } } } } }),
            ),
        );
        renderWithProviders(<WebhooksPage />);
        await screen.findByText('evt_ok');

        await userEvent.click(within(row('evt_ok')).getByRole('button', { name: /View/ }));

        const dialog = await screen.findByRole('dialog');
        expect(await within(dialog).findByText(/"acct_1"/)).toBeInTheDocument();
    });

    it('replays a failed event and refreshes the list', async () => {
        let replayed = false;
        server.use(
            http.post(
                url('/admin/webhook-events/e2/replay'),
                () => ((replayed = true), HttpResponse.json({ data: { ...bad, status: 'processed', error: null } })),
            ),
        );
        renderWithProviders(<WebhooksPage />);
        await screen.findByText('evt_bad');
        const before = requests.length;

        await userEvent.click(within(row('evt_bad')).getByRole('button', { name: /Replay/ }));

        await waitFor(() => expect(replayed).toBe(true));
        await waitFor(() => expect(requests.length).toBeGreaterThan(before));
    });

    it('offers no replay for an event that already succeeded', async () => {
        renderWithProviders(<WebhooksPage />);
        await screen.findByText('evt_ok');

        expect(within(row('evt_ok')).queryByRole('button', { name: /Replay/ })).not.toBeInTheDocument();
    });

    it('shows why a replay failed', async () => {
        server.use(
            http.post(url('/admin/webhook-events/e2/replay'), () =>
                HttpResponse.json({ message: 'The event failed again: still broken', code: 'replay_failed' }, { status: 422 }),
            ),
        );
        renderWithProviders(<WebhooksPage />);
        await screen.findByText('evt_bad');

        await userEvent.click(within(row('evt_bad')).getByRole('button', { name: /Replay/ }));

        expect(await screen.findByRole('alert')).toHaveTextContent('still broken');
    });
});
