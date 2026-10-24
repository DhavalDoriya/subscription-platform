import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { EmailLogsPage } from './EmailLogsPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const sent = {
    id: 'l1',
    template_key: 'dunning.payment_failed',
    provider_id: null,
    to_email: 'ada@example.com',
    subject: 'Payment failed',
    status: 'sent',
    error: null,
    attempts: 1,
    sent_at: '2026-11-02T10:00:00Z',
    created_at: '2026-11-02T10:00:00Z',
};
const failed = { ...sent, id: 'l2', to_email: 'bob@example.com', status: 'failed', error: 'Connection refused', sent_at: null };
const requests: URL[] = [];

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    requests.length = 0;
    server.use(
        http.get(url('/admin/email-logs'), ({ request }) => {
            requests.push(new URL(request.url));
            return HttpResponse.json({ data: [sent, failed], meta: { current_page: 1, per_page: 15, total: 2, last_page: 1 } });
        }),
    );
});
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

const row = (name: string) => screen.getByRole('row', { name: new RegExp(name) });

describe('FR-E6 email delivery log', () => {
    it('lists emails with their status and error', async () => {
        renderWithProviders(<EmailLogsPage />);

        expect(await screen.findByText('ada@example.com')).toBeInTheDocument();
        expect(within(row('ada@example.com')).getByText('Sent')).toBeInTheDocument();
        expect(within(row('bob@example.com')).getByText('Failed')).toBeInTheDocument();
        expect(within(row('bob@example.com')).getByText('Connection refused')).toBeInTheDocument();
    });

    it('filters by status and searches', async () => {
        renderWithProviders(<EmailLogsPage />);
        await screen.findByText('ada@example.com');

        await userEvent.selectOptions(screen.getByLabelText('Status'), 'failed');
        await waitFor(() => expect(requests.at(-1)!.searchParams.get('status')).toBe('failed'));
        await userEvent.type(screen.getByRole('searchbox', { name: 'Search emails' }), 'bob');
        await waitFor(() => expect(requests.at(-1)!.searchParams.get('search')).toBe('bob'));
    });

    it('shows what was sent', async () => {
        server.use(
            http.get(url('/admin/email-logs/l1'), () => HttpResponse.json({ data: { ...sent, html_body: '<p>Hello Ada</p>', text_body: 'Hello Ada' } })),
        );
        renderWithProviders(<EmailLogsPage />);
        await screen.findByText('ada@example.com');

        await userEvent.click(within(row('ada@example.com')).getByRole('button', { name: /View/ }));

        const dialog = await screen.findByRole('dialog');
        await waitFor(() => expect(within(dialog).getByTitle('Sent email')).toHaveAttribute('srcdoc', expect.stringContaining('Hello Ada')));
    });

    it('resends only a failed email and refreshes the list', async () => {
        let resent = false;
        server.use(
            http.post(url('/admin/email-logs/l2/resend'), () => ((resent = true), HttpResponse.json({ data: { ...failed, status: 'sent', error: null } }))),
        );
        renderWithProviders(<EmailLogsPage />);
        await screen.findByText('bob@example.com');
        const before = requests.length;

        expect(within(row('ada@example.com')).queryByRole('button', { name: /Resend/ })).not.toBeInTheDocument();
        await userEvent.click(within(row('bob@example.com')).getByRole('button', { name: /Resend/ }));

        await waitFor(() => expect(resent).toBe(true));
        await waitFor(() => expect(requests.length).toBeGreaterThan(before));
    });
});
