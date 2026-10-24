import { screen } from '@testing-library/react';
import { useAuthStore, type Role } from '@/lib/auth';
import { renderWithProviders } from '@/test/render';
import { AccountPage } from './AccountPage';

afterEach(() => useAuthStore.getState().clear());

function setup(roles: Role[]) {
    useAuthStore.getState().setSession({ id: 'u1', name: 'Olive', email: 'olive@example.com', email_verified_at: null, avatar_url: null, roles }, 't');
    return renderWithProviders(<AccountPage />);
}

describe('account page', () => {
    it('offers a customer the way to become a provider', () => {
        setup(['customer']);
        expect(screen.getByRole('link', { name: 'Become a provider' })).toHaveAttribute('href', '/become-a-provider');
        expect(screen.queryByRole('link', { name: 'Provider dashboard' })).not.toBeInTheDocument();
    });

    it('sends a provider owner to their dashboard instead', () => {
        setup(['customer', 'provider_owner']);
        expect(screen.getByRole('link', { name: 'Provider dashboard' })).toHaveAttribute('href', '/dashboard');
        expect(screen.queryByRole('link', { name: 'Become a provider' })).not.toBeInTheDocument();
    });
});
