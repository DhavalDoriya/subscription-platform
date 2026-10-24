import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { AuditLogPage } from './AuditLogPage';

const entry = (over: object) => ({
    id: 'l1',
    description: 'provider.approved',
    causer: { id: 'a1', name: 'Ada Admin', email: 'ada@example.com' },
    subject_type: 'App\\Models\\Provider',
    subject_id: 'p1',
    properties: { from: 'pending', to: 'approved' },
    created_at: '2026-10-02T10:30:00Z',
    ...over,
});
const requests: URL[] = [];
const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    requests.length = 0;
    server.use(
        http.get('http://localhost/api/v1/admin/audit-logs', ({ request }) => {
            requests.push(new URL(request.url));
            return HttpResponse.json({
                data: [
                    entry({}),
                    entry({
                        id: 'l2',
                        description: 'settings.updated',
                        causer: null,
                        subject_type: null,
                        subject_id: null,
                        properties: { changes: { 'provider.max_plans': 3 } },
                    }),
                ],
                meta: { current_page: 1, per_page: 15, total: 2, last_page: 1 },
            });
        }),
    );
});
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe('FR-AD10 audit log page', () => {
    it('shows who did what, newest first by default', async () => {
        renderWithProviders(<AuditLogPage />);

        expect(await screen.findByText('provider.approved')).toBeInTheDocument();
        const rows = screen.getAllByRole('row');
        expect(within(rows[1]).getByText('Ada Admin')).toBeInTheDocument();
        expect(within(rows[2]).getByText('System')).toBeInTheDocument(); // no causer
        expect(requests[0].searchParams.get('direction')).toBe('desc');
    });

    it('summarises the change in the row', async () => {
        renderWithProviders(<AuditLogPage />);
        await screen.findByText('provider.approved');

        expect(screen.getByText('pending → approved')).toBeInTheDocument();
        expect(screen.getByText('provider.max_plans: 3')).toBeInTheDocument();
    });

    it('filters by event name', async () => {
        renderWithProviders(<AuditLogPage />);
        await screen.findByText('provider.approved');

        await userEvent.type(screen.getByRole('searchbox', { name: 'Search events' }), 'settings');

        await waitFor(() => expect(requests.at(-1)!.searchParams.get('search')).toBe('settings'));
    });
});
