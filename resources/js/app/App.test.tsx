import { render, screen } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { App } from './App';

const server = setupServer(http.post('http://localhost/api/v1/auth/refresh', () => HttpResponse.json({ message: 'none' }, { status: 401 })));
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
afterAll(() => server.close());

describe('App', () => {
    it('sends a visitor without a session to the sign-in page', async () => {
        render(<App />);
        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
    });
});
