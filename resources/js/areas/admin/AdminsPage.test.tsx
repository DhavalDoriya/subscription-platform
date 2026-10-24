import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { useAuthStore } from '@/lib/auth';
import { AdminsPage } from './AdminsPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const me = { id: 'a1', name: 'Sue Super', email: 'sue@example.com', email_verified_at: '2026-01-01T00:00:00Z', avatar_url: null, roles: ['super_admin'] };
const dan = { id: 'a2', name: 'Dan Admin', email: 'dan@example.com', email_verified_at: '2026-01-01T00:00:00Z', avatar_url: null, roles: ['admin'] };
let listCalls = 0;

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    listCalls = 0;
    useAuthStore.getState().setSession(me as never, 't');
    server.use(
        http.get(url('/admin/admins'), () => {
            listCalls++;
            return HttpResponse.json({ data: [me, dan], meta: { current_page: 1, per_page: 15, total: 2, last_page: 1 } });
        }),
    );
});
afterEach(() => {
    server.resetHandlers();
    useAuthStore.getState().clear();
});
afterAll(() => server.close());

const row = (name: string) => screen.getByRole('row', { name: new RegExp(name) });

describe('FR-AD9 admin users page', () => {
    it('lists admins with their role', async () => {
        renderWithProviders(<AdminsPage />);

        expect(await screen.findByRole('cell', { name: /Dan Admin/ })).toBeInTheDocument();
        expect(within(row('Dan Admin')).getByText('Admin')).toBeInTheDocument();
        expect(within(row('Sue Super')).getByText('Super admin')).toBeInTheDocument();
    });

    it('does not offer removing yourself or a super admin', async () => {
        renderWithProviders(<AdminsPage />);
        await screen.findByRole('cell', { name: /Dan Admin/ });

        expect(within(row('Sue Super')).queryByRole('button', { name: /Remove/ })).not.toBeInTheDocument();
        expect(within(row('Dan Admin')).getByRole('button', { name: /Remove/ })).toBeInTheDocument();
    });

    it('adds an admin and refreshes the list', async () => {
        let body: unknown;
        server.use(
            http.post(url('/admin/admins'), async ({ request }) => {
                body = await request.json();
                return HttpResponse.json({ data: { ...dan, id: 'a3', name: 'New Person' } }, { status: 201 });
            }),
        );
        renderWithProviders(<AdminsPage />);
        await screen.findByRole('cell', { name: /Dan Admin/ });

        await userEvent.click(screen.getByRole('button', { name: 'Add admin' }));
        const dialog = await screen.findByRole('dialog', { name: 'Add admin' });
        await userEvent.type(within(dialog).getByLabelText('Name'), 'New Person');
        await userEvent.type(within(dialog).getByLabelText('Email'), 'new@example.com');
        await userEvent.type(within(dialog).getByLabelText('Password'), 'Password123!');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Add admin' }));

        await waitFor(() => expect(body).toEqual({ name: 'New Person', email: 'new@example.com', password: 'Password123!' }));
        await waitFor(() => expect(listCalls).toBeGreaterThan(1));
    });

    it('shows field errors from the API', async () => {
        server.use(
            http.post(url('/admin/admins'), () =>
                HttpResponse.json({ message: 'invalid', errors: { email: ['The email has already been taken.'] } }, { status: 422 }),
            ),
        );
        renderWithProviders(<AdminsPage />);
        await screen.findByRole('cell', { name: /Dan Admin/ });

        await userEvent.click(screen.getByRole('button', { name: 'Add admin' }));
        const dialog = await screen.findByRole('dialog', { name: 'Add admin' });
        await userEvent.click(within(dialog).getByRole('button', { name: 'Add admin' }));

        expect(await within(dialog).findByText('The email has already been taken.')).toBeInTheDocument();
    });

    it('removes an admin after confirmation', async () => {
        let removed = false;
        server.use(http.delete(url('/admin/admins/a2'), () => ((removed = true), new HttpResponse(null, { status: 204 }))));
        renderWithProviders(<AdminsPage />);
        await screen.findByRole('cell', { name: /Dan Admin/ });

        await userEvent.click(within(row('Dan Admin')).getByRole('button', { name: /Remove/ }));
        const confirm = await screen.findByRole('alertdialog');
        expect(removed).toBe(false);
        await userEvent.click(within(confirm).getByRole('button', { name: 'Remove' }));

        await waitFor(() => expect(removed).toBe(true));
    });
});
