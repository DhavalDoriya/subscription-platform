import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { MemoryRouter } from 'react-router-dom';
import { useAuthStore } from '@/lib/auth';
import { ProfilePage } from './ProfilePage';

const user = { id: 'u1', name: 'Jane', email: 'jane@example.com', email_verified_at: '2026-01-01T00:00:00Z', avatar_url: null, roles: ['customer'] };
const url = (p: string) => `http://localhost/api/v1${p}`;
const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => useAuthStore.getState().setSession(user as never, 'token'));
afterEach(() => {
    cleanup(); // unmount before the store is cleared, or the page re-renders without a user
    server.resetHandlers();
    useAuthStore.getState().clear();
});
afterAll(() => server.close());

const renderPage = () =>
    render(
        <MemoryRouter>
            <ProfilePage />
        </MemoryRouter>,
    );

describe('FR-A8 profile page', () => {
    it('saves a new name and updates the signed-in user', async () => {
        let body: unknown;
        server.use(
            http.patch(url('/auth/profile'), async ({ request }) => {
                body = await request.json();
                return HttpResponse.json({ data: { ...user, name: 'Jane Q' } });
            }),
        );
        renderPage();

        const name = screen.getByLabelText('Name');
        await userEvent.clear(name);
        await userEvent.type(name, 'Jane Q');
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(await screen.findByRole('status')).toHaveTextContent('Profile saved');
        expect(body).toEqual({ name: 'Jane Q' });
        expect(useAuthStore.getState().user?.name).toBe('Jane Q');
    });

    it('asks for the current password only when the email changes', async () => {
        renderPage();
        expect(screen.queryByLabelText('Confirm with your password')).not.toBeInTheDocument();

        await userEvent.clear(screen.getByLabelText('Email'));
        await userEvent.type(screen.getByLabelText('Email'), 'new@example.com');

        expect(screen.getByLabelText('Confirm with your password')).toBeInTheDocument();
    });

    it('shows API field errors next to the input', async () => {
        server.use(
            http.patch(url('/auth/profile'), () =>
                HttpResponse.json({ message: 'invalid', errors: { email: ['The email has already been taken.'] } }, { status: 422 }),
            ),
        );
        renderPage();

        await userEvent.clear(screen.getByLabelText('Email'));
        await userEvent.type(screen.getByLabelText('Email'), 'taken@example.com');
        await userEvent.type(screen.getByLabelText('Confirm with your password'), 'Password123!');
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(screen.getByLabelText('Email')).toHaveAccessibleDescription('The email has already been taken.'));
    });

    it('changes the password and keeps the session the server issues', async () => {
        let body: unknown;
        server.use(
            http.put(url('/auth/password'), async ({ request }) => {
                body = await request.json();
                return HttpResponse.json({ data: { access_token: 'fresh', token_type: 'bearer', expires_in: 900, user } });
            }),
        );
        renderPage();

        await userEvent.type(screen.getByLabelText('Current password'), 'Password123!');
        await userEvent.type(screen.getByLabelText('New password'), 'NewPassword456!');
        await userEvent.type(screen.getByLabelText('Confirm new password'), 'NewPassword456!');
        await userEvent.click(screen.getByRole('button', { name: 'Update password' }));

        expect(await screen.findByText(/Password updated/)).toBeInTheDocument();
        expect(body).toEqual({ current_password: 'Password123!', password: 'NewPassword456!', password_confirmation: 'NewPassword456!' });
        expect(useAuthStore.getState().accessToken).toBe('fresh');
    });

    it('uploads an avatar and shows it', async () => {
        server.use(http.post(url('/auth/avatar'), () => HttpResponse.json({ data: { ...user, avatar_url: 'http://localhost/storage/avatars/a.png' } })));
        renderPage();

        await userEvent.upload(screen.getByLabelText('Upload photo'), new File(['x'], 'me.png', { type: 'image/png' }));

        await waitFor(() => expect(screen.getByRole('img', { name: 'Your photo' })).toHaveAttribute('src', 'http://localhost/storage/avatars/a.png'));
    });

    it('removes the avatar', async () => {
        useAuthStore.getState().setSession({ ...user, avatar_url: 'http://localhost/storage/avatars/a.png' } as never, 'token');
        server.use(
            http.delete(url('/auth/avatar'), () => new HttpResponse(null, { status: 204 })),
            http.get(url('/auth/me'), () => HttpResponse.json({ data: { ...user, avatar_url: null } })),
        );
        renderPage();

        await userEvent.click(screen.getByRole('button', { name: 'Remove photo' }));

        await waitFor(() => expect(screen.queryByRole('img', { name: 'Your photo' })).not.toBeInTheDocument());
    });
});
