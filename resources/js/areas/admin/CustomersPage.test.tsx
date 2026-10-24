import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render } from '@testing-library/react';
import { useAuthStore } from '@/lib/auth';
import { CustomersPage } from './CustomersPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const ann = {
    id: 'c1',
    name: 'Ann Lee',
    email: 'ann@example.com',
    email_verified_at: '2026-01-01T00:00:00Z',
    is_blocked: false,
    blocked_at: null,
    created_at: '2026-09-01T00:00:00Z',
};
const bob = { ...ann, id: 'c2', name: 'Bob Ray', email: 'bob@example.com', is_blocked: true, blocked_at: '2026-10-01T00:00:00Z' };
const requests: URL[] = [];

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    requests.length = 0;
    useAuthStore
        .getState()
        .setSession({ id: 'a1', name: 'Ada Admin', email: 'ada@example.com', email_verified_at: null, avatar_url: null, roles: ['admin'] }, 'admin-token');
    server.use(
        http.get(url('/admin/customers'), ({ request }) => {
            requests.push(new URL(request.url));
            return HttpResponse.json({ data: [ann, bob], meta: { current_page: 1, per_page: 15, total: 2, last_page: 1 } });
        }),
    );
});
afterEach(() => {
    server.resetHandlers();
    useAuthStore.getState().clear();
});
afterAll(() => server.close());

function setup() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <MemoryRouter initialEntries={['/admin/customers']}>
                <Routes>
                    <Route path="/admin/customers" element={<CustomersPage />} />
                    <Route path="/account" element={<h1>Customer account</h1>} />
                </Routes>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}
const row = (name: string) => screen.getByRole('row', { name: new RegExp(name) });

describe('FR-AD3 customers page', () => {
    it('lists customers and flags blocked ones', async () => {
        setup();
        expect(await screen.findByRole('cell', { name: /Ann Lee/ })).toBeInTheDocument();
        expect(within(row('Bob Ray')).getByText('Blocked')).toBeInTheDocument();
        expect(within(row('Ann Lee')).getByText('Active')).toBeInTheDocument();
    });

    it('filters by blocked state', async () => {
        setup();
        await screen.findByRole('cell', { name: /Ann Lee/ });
        await userEvent.selectOptions(screen.getByLabelText('Access'), 'blocked');

        await waitFor(() => expect(requests.at(-1)!.searchParams.get('blocked')).toBe('1'));
    });

    it('blocks after confirmation', async () => {
        let blocked = false;
        server.use(http.post(url('/admin/customers/c1/block'), () => ((blocked = true), HttpResponse.json({ data: { ...ann, is_blocked: true } }))));
        setup();
        await screen.findByRole('cell', { name: /Ann Lee/ });

        await userEvent.click(within(row('Ann Lee')).getByRole('button', { name: /Block/ }));
        const confirm = await screen.findByRole('alertdialog');
        expect(confirm).toHaveTextContent(/signed out/i);
        expect(blocked).toBe(false);
        await userEvent.click(within(confirm).getByRole('button', { name: 'Block' }));

        await waitFor(() => expect(blocked).toBe(true));
    });

    it('unblocks without a confirmation step', async () => {
        let unblocked = false;
        server.use(http.post(url('/admin/customers/c2/unblock'), () => ((unblocked = true), HttpResponse.json({ data: { ...bob, is_blocked: false } }))));
        setup();
        await screen.findByRole('cell', { name: /Bob Ray/ });

        await userEvent.click(within(row('Bob Ray')).getByRole('button', { name: /Unblock/ }));

        await waitFor(() => expect(unblocked).toBe(true));
    });

    it('shows the providers a customer subscribed to', async () => {
        server.use(
            http.get(url('/admin/customers/c1'), () =>
                HttpResponse.json({ data: { ...ann, providers: [{ id: 'p1', name: 'Acme Fitness', slug: 'acme', joined_at: '2026-10-01T00:00:00Z' }] } }),
            ),
        );
        setup();
        await screen.findByRole('cell', { name: /Ann Lee/ });

        await userEvent.click(within(row('Ann Lee')).getByRole('button', { name: /View/ }));

        const dialog = await screen.findByRole('dialog');
        expect(await within(dialog).findByText('Acme Fitness')).toBeInTheDocument();
    });

    it('impersonates a customer: switches the session and opens their account', async () => {
        server.use(
            http.post(url('/admin/users/c1/impersonate'), () =>
                HttpResponse.json({
                    data: {
                        access_token: 'imp-token',
                        token_type: 'bearer',
                        expires_in: 900,
                        user: { id: 'c1', name: 'Ann Lee', email: 'ann@example.com', email_verified_at: null, avatar_url: null, roles: ['customer'] },
                        impersonator: { id: 'a1', name: 'Ada Admin' },
                    },
                }),
            ),
        );
        setup();
        await screen.findByRole('cell', { name: /Ann Lee/ });

        await userEvent.click(within(row('Ann Lee')).getByRole('button', { name: /Impersonate/ }));

        expect(await screen.findByRole('heading', { name: 'Customer account' })).toBeInTheDocument();
        expect(useAuthStore.getState().user?.id).toBe('c1');
        expect(useAuthStore.getState().accessToken).toBe('imp-token');
        expect(useAuthStore.getState().impersonator?.name).toBe('Ada Admin');
    });
});
