import { render, screen } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { App } from './App';
import { afterEach } from 'vitest';

const server = setupServer(http.post('http://localhost/api/v1/auth/refresh', () => HttpResponse.json({ message: 'none' }, { status: 401 })));
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
afterAll(() => server.close());

describe('App', () => {
    it('sends a visitor without a session to the sign-in page', async () => {
        render(<App />);
        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
    });

    it('shows the provider storefront, not the platform app, on a provider subdomain', async () => {
        window.__APP__ = { baseDomain: 'platform.test' };
        server.use(
            http.get('http://localhost/api/v1/public/storefront', () => HttpResponse.json({ message: 'No provider lives at this address.' }, { status: 404 })),
        );
        render(<App host="ghost.platform.test" />);

        expect(await screen.findByRole('heading', { name: /couldn.t find/i })).toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'Sign in' })).not.toBeInTheDocument();
    });
});

afterEach(() => {
    window.__APP__ = undefined;
});
