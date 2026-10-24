import { render, screen, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { useAuthStore, type Role } from '@/lib/auth';
import { AdminLayout } from './AdminLayout';

const server = setupServer(http.post('http://localhost/api/v1/auth/logout', () => new HttpResponse(null, { status: 204 })));
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
afterEach(() => {
    server.resetHandlers();
    useAuthStore.getState().clear();
});
afterAll(() => server.close());

function renderAs(roles: Role[], path = '/admin') {
    useAuthStore.getState().setSession({ id: 'u1', name: 'Ada Admin', email: 'ada@example.com', email_verified_at: null, avatar_url: null, roles }, 't');
    return render(
        <MemoryRouter initialEntries={[path]}>
            <Routes>
                <Route path="/admin" element={<AdminLayout />}>
                    <Route index element={<h1>Dashboard page</h1>} />
                    <Route path="providers" element={<h1>Providers page</h1>} />
                </Route>
                <Route path="/" element={<h1>Home</h1>} />
            </Routes>
        </MemoryRouter>,
    );
}

describe('AdminLayout', () => {
    it('groups the sidebar by domain', () => {
        renderAs(['admin']);
        const nav = screen.getByRole('navigation', { name: 'Admin' });
        expect(
            within(nav)
                .getAllByRole('heading')
                .map((h) => h.textContent),
        ).toEqual(['Overview', 'Marketplace', 'People', 'System']);
        for (const link of ['Dashboard', 'Providers', 'Customers', 'Audit log', 'Settings']) {
            expect(within(nav).getByRole('link', { name: link })).toBeInTheDocument();
        }
    });

    it('shows Admins only to super admins', () => {
        const { unmount } = renderAs(['admin']);
        expect(screen.queryByRole('link', { name: 'Admins' })).not.toBeInTheDocument();
        unmount();
        renderAs(['super_admin']);
        expect(screen.getByRole('link', { name: 'Admins' })).toBeInTheDocument();
    });

    it('marks the current page and renders it', () => {
        renderAs(['admin'], '/admin/providers');
        expect(screen.getByRole('link', { name: 'Providers' })).toHaveAttribute('aria-current', 'page');
        expect(screen.getByRole('heading', { name: 'Providers page' })).toBeInTheDocument();
    });

    it('shows who is signed in and signs out', async () => {
        renderAs(['admin']);
        expect(screen.getByText('Ada Admin')).toBeInTheDocument();
        screen.getByRole('button', { name: 'Sign out' }).click();
        expect(await screen.findByRole('heading', { name: 'Home' })).toBeInTheDocument();
    });
});
