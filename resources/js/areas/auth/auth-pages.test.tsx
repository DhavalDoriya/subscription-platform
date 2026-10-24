import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { RequireAuth, useAuthStore } from '@/lib/auth';
import { LoginPage } from './LoginPage';
import { RegisterPage } from './RegisterPage';

const user = { id: 'u1', name: 'Jane', email: 'jane@example.com', email_verified_at: null, roles: ['customer'] };
const url = (p: string) => `http://localhost/api/v1${p}`;
const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
afterEach(() => {
    server.resetHandlers();
    useAuthStore.getState().clear();
});
afterAll(() => server.close());

function renderAt(path: string) {
    return render(
        <MemoryRouter initialEntries={[path]}>
            <Routes>
                <Route path="/login" element={<LoginPage />} />
                <Route path="/register" element={<RegisterPage />} />
                <Route path="/account" element={<RequireAuth><h1>Account</h1></RequireAuth>} />
                <Route path="/admin" element={<RequireAuth roles={['admin']}><h1>Admin</h1></RequireAuth>} />
                <Route path="/" element={<h1>Home</h1>} />
            </Routes>
        </MemoryRouter>,
    );
}

describe('login page', () => {
    it('signs in and goes to the account page', async () => {
        server.use(http.post(url('/auth/login'), () => HttpResponse.json({ data: { access_token: 't', token_type: 'bearer', expires_in: 900, user } })));
        renderAt('/login');

        await userEvent.type(screen.getByLabelText('Email'), 'jane@example.com');
        await userEvent.type(screen.getByLabelText('Password'), 'Password123!');
        await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));

        expect(await screen.findByRole('heading', { name: 'Account' })).toBeInTheDocument();
    });

    it('shows the server message for wrong credentials', async () => {
        server.use(http.post(url('/auth/login'), () => HttpResponse.json({ message: 'Invalid email or password.', code: 'invalid_credentials' }, { status: 401 })));
        renderAt('/login');

        await userEvent.type(screen.getByLabelText('Email'), 'jane@example.com');
        await userEvent.type(screen.getByLabelText('Password'), 'bad');
        await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Invalid email or password.');
    });
});

describe('register page', () => {
    it('shows field errors from the API next to the inputs', async () => {
        server.use(http.post(url('/auth/register'), () => HttpResponse.json({ message: 'invalid', errors: { email: ['The email has already been taken.'] } }, { status: 422 })));
        renderAt('/register');

        await userEvent.click(screen.getByRole('button', { name: 'Create account' }));

        const email = await screen.findByLabelText('Email');
        await waitFor(() => expect(email).toHaveAccessibleDescription('The email has already been taken.'));
        expect(email).toBeInvalid();
    });
});

describe('route guards', () => {
    it('redirects guests to the login page', () => {
        useAuthStore.getState().clear();
        renderAt('/account');
        expect(screen.getByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
    });

    it('keeps users without the role out of admin pages', () => {
        useAuthStore.getState().setSession(user as never, 't');
        renderAt('/admin');
        expect(screen.getByRole('heading', { name: 'Home' })).toBeInTheDocument();
    });
});
