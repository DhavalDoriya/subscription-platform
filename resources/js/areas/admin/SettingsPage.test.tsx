import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { useAuthStore } from '@/lib/auth';
import { SettingsPage } from './SettingsPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const settings = {
    general: { platform_name: { value: 'Billing Platform' }, support_email: { value: null }, default_currency: { value: 'usd' } },
    provider: {
        auto_approve: { value: true },
        reserved_slugs: { value: ['secret'] },
        max_plans: { value: 10 },
        allowed_currencies: { value: ['usd', 'eur'] },
        allowed_intervals: { value: ['month', 'year'] },
    },
    stripe: { secret_key: { is_set: true }, webhook_secret: { is_set: false }, connect_webhook_secret: { is_set: false }, tax_enabled: { value: false } },
    dunning: { retry_days: { value: ['1', '3', '5', '7'] }, grace_days: { value: 7 }, final_action: { value: 'cancel' } },
    security: { min_password_length: { value: 8 } },
};
const stripeInfo = {
    configured: true,
    mode: 'test',
    webhook_url: 'https://app.test/api/v1/webhooks/stripe',
    connect_webhook_url: 'https://app.test/api/v1/webhooks/stripe/connect',
    webhook_secret_set: true,
    connect_webhook_secret_set: false,
};
const user = (role: 'admin' | 'super_admin') => ({ id: 'a1', name: 'Ada', email: 'ada@example.com', email_verified_at: null, avatar_url: null, roles: [role] });

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() =>
    server.use(
        http.get(url('/admin/settings'), () => HttpResponse.json({ data: settings })),
        http.get(url('/admin/stripe'), () => HttpResponse.json({ data: stripeInfo })),
    ),
);
afterEach(() => {
    server.resetHandlers();
    useAuthStore.getState().clear();
});
afterAll(() => server.close());

describe('FR-AD7 settings page', () => {
    it('loads the current values', async () => {
        useAuthStore.getState().setSession(user('admin') as never, 't');
        renderWithProviders(<SettingsPage />);

        expect(await screen.findByLabelText('Platform name')).toHaveValue('Billing Platform');
        expect(screen.getByLabelText('Auto-approve new providers')).toBeChecked();
        expect(screen.getByLabelText('Maximum plans per provider')).toHaveValue(10);
        expect(screen.getByLabelText('Enabled currencies')).toHaveValue('usd, eur');
    });

    it('saves only what changed, nested by group', async () => {
        useAuthStore.getState().setSession(user('admin') as never, 't');
        let body: unknown;
        server.use(
            http.put(url('/admin/settings'), async ({ request }) => {
                body = await request.json();
                return HttpResponse.json({ data: settings });
            }),
        );
        renderWithProviders(<SettingsPage />);

        await userEvent.click(await screen.findByLabelText('Auto-approve new providers'));
        await userEvent.clear(screen.getByLabelText('Enabled currencies'));
        await userEvent.type(screen.getByLabelText('Enabled currencies'), 'usd, cad');
        await userEvent.click(screen.getByRole('button', { name: 'Save settings' }));

        await waitFor(() => expect(body).toEqual({ settings: { provider: { auto_approve: false, allowed_currencies: ['usd', 'cad'] } } }));
    });

    it('FR-R2 edits the retry schedule, the grace period and the final action', async () => {
        useAuthStore.getState().setSession(user('admin') as never, 't');
        let body: unknown;
        server.use(
            http.put(url('/admin/settings'), async ({ request }) => {
                body = await request.json();
                return HttpResponse.json({ data: settings });
            }),
        );
        renderWithProviders(<SettingsPage />);

        expect(await screen.findByLabelText('Retry after (days)')).toHaveValue('1, 3, 5, 7');
        expect(screen.getByLabelText('Grace period (days)')).toHaveValue(7);
        expect(screen.getByLabelText('When grace ends')).toHaveValue('cancel');

        await userEvent.clear(screen.getByLabelText('Retry after (days)'));
        await userEvent.type(screen.getByLabelText('Retry after (days)'), '2, 4');
        await userEvent.selectOptions(screen.getByLabelText('When grace ends'), 'pause');
        await userEvent.click(screen.getByRole('button', { name: 'Save settings' }));

        await waitFor(() => expect(body).toEqual({ settings: { dunning: { retry_days: ['2', '4'], final_action: 'pause' } } }));
    });

    it('shows API validation errors next to the field', async () => {
        useAuthStore.getState().setSession(user('admin') as never, 't');
        server.use(
            http.put(url('/admin/settings'), () =>
                HttpResponse.json({ message: 'invalid', errors: { 'settings.provider.max_plans': ['The max plans must be an integer.'] } }, { status: 422 }),
            ),
        );
        renderWithProviders(<SettingsPage />);

        const input = await screen.findByLabelText('Maximum plans per provider');
        await userEvent.clear(input);
        await userEvent.type(input, '5');
        await userEvent.click(screen.getByRole('button', { name: 'Save settings' }));

        await waitFor(() => expect(input).toHaveAccessibleDescription('The max plans must be an integer.'));
    });

    it('never shows a secret; super admins can replace it, admins cannot', async () => {
        useAuthStore.getState().setSession(user('admin') as never, 't');
        const { unmount } = renderWithProviders(<SettingsPage />);
        const stripeAsAdmin = await screen.findByRole('region', { name: 'Stripe' });
        expect(within(stripeAsAdmin).getByText('Secret key')).toBeInTheDocument();
        expect(within(stripeAsAdmin).getByText('Set')).toBeInTheDocument();
        expect(within(stripeAsAdmin).queryByLabelText('Secret key')).not.toBeInTheDocument();
        unmount();

        useAuthStore.getState().setSession(user('super_admin') as never, 't');
        let body: unknown;
        server.use(
            http.put(url('/admin/settings'), async ({ request }) => {
                body = await request.json();
                return HttpResponse.json({ data: settings });
            }),
        );
        renderWithProviders(<SettingsPage />);
        const secret = await screen.findByLabelText('Secret key');
        expect(secret).toHaveValue('');
        expect(secret).toHaveAttribute('type', 'password');
        await userEvent.type(secret, 'sk_test_new');
        await userEvent.click(screen.getByRole('button', { name: 'Save settings' }));

        await waitFor(() => expect(body).toEqual({ settings: { stripe: { secret_key: 'sk_test_new' } } }));
    });
});

describe('FR-AD7 Stripe connection panel', () => {
    it('shows the mode and the webhook URLs to register in Stripe, and which secrets are set', async () => {
        useAuthStore.getState().setSession(user('admin') as never, 't');
        renderWithProviders(<SettingsPage />);

        const panel = await screen.findByRole('region', { name: 'Stripe connection' });
        expect(within(panel).getByText('Test mode')).toBeInTheDocument();
        expect(within(panel).getByText('https://app.test/api/v1/webhooks/stripe')).toBeInTheDocument();
        expect(within(panel).getByText('https://app.test/api/v1/webhooks/stripe/connect')).toBeInTheDocument();
        expect(within(panel).getByText('Webhook secret: set')).toBeInTheDocument();
        expect(within(panel).getByText('Connect webhook secret: not set')).toBeInTheDocument();
    });

    it('says when Stripe is not configured yet', async () => {
        useAuthStore.getState().setSession(user('admin') as never, 't');
        server.use(http.get(url('/admin/stripe'), () => HttpResponse.json({ data: { ...stripeInfo, configured: false, mode: null } })));
        renderWithProviders(<SettingsPage />);

        expect(await within(await screen.findByRole('region', { name: 'Stripe connection' })).findByText('Not configured')).toBeInTheDocument();
    });
});

describe('FR-PL10 price limits', () => {
    it('edits the minimum and maximum price in money, saving minor units', async () => {
        useAuthStore.getState().setSession(user('admin') as never, 't');
        let body: unknown;
        server.use(
            http.get(url('/admin/settings'), () =>
                HttpResponse.json({ data: { ...settings, provider: { ...settings.provider, min_price: { value: 100 }, max_price: { value: 1000000 } } } }),
            ),
            http.put(url('/admin/settings'), async ({ request }) => ((body = await request.json()), HttpResponse.json({ data: settings }))),
        );
        renderWithProviders(<SettingsPage />);

        const min = await screen.findByLabelText('Minimum price (minor units)');
        expect(min).toHaveValue(100);
        await userEvent.clear(min);
        await userEvent.type(min, '250');
        await userEvent.click(screen.getByRole('button', { name: 'Save settings' }));

        await waitFor(() => expect(body).toEqual({ settings: { provider: { min_price: 250 } } }));
    });
});

describe('FR-E5 mail settings', () => {
    const withMail = {
        ...settings,
        mail: {
            driver: { value: 'default' },
            host: { value: null },
            port: { value: null },
            username: { value: null },
            password: { is_set: true },
            encryption: { value: 'tls' },
            api_key: { is_set: false },
            api_domain: { value: null },
            from_address: { value: null },
            from_name: { value: null },
        },
    };

    beforeEach(() => server.use(http.get(url('/admin/settings'), () => HttpResponse.json({ data: withMail }))));

    it('saves the mail server details and keeps the password write-only', async () => {
        useAuthStore.getState().setSession(user('super_admin') as never, 't');
        let body: unknown;
        server.use(http.put(url('/admin/settings'), async ({ request }) => ((body = await request.json()), HttpResponse.json({ data: withMail }))));
        renderWithProviders(<SettingsPage />);

        const mail = await screen.findByRole('region', { name: 'Mail' });
        expect(within(mail).getByLabelText('Password')).toHaveValue('');
        await userEvent.selectOptions(within(mail).getByLabelText('Mail driver'), 'smtp');
        await userEvent.type(within(mail).getByLabelText('SMTP host'), 'smtp.example.com');
        await userEvent.type(within(mail).getByLabelText('SMTP port'), '587');
        await userEvent.type(within(mail).getByLabelText('Password'), 's3cret');
        await userEvent.click(screen.getByRole('button', { name: 'Save settings' }));

        await waitFor(() => expect(body).toEqual({ settings: { mail: { driver: 'smtp', host: 'smtp.example.com', port: 587, password: 's3cret' } } }));
    });

    it('sends a test email and reports the outcome', async () => {
        useAuthStore.getState().setSession(user('admin') as never, 't');
        let body: unknown;
        server.use(http.post(url('/admin/mail/test'), async ({ request }) => ((body = await request.json()), HttpResponse.json({ data: { sent: true } }))));
        renderWithProviders(<SettingsPage />);

        const panel = await screen.findByRole('region', { name: 'Test email' });
        await userEvent.type(within(panel).getByLabelText('Send a test email to'), 'me@example.com');
        await userEvent.click(within(panel).getByRole('button', { name: 'Send test email' }));

        await waitFor(() => expect(body).toEqual({ to: 'me@example.com' }));
        expect(await within(panel).findByText('Test email sent to me@example.com.')).toBeInTheDocument();
    });

    it('shows why the test email failed', async () => {
        useAuthStore.getState().setSession(user('admin') as never, 't');
        server.use(
            http.post(url('/admin/mail/test'), () =>
                HttpResponse.json({ message: 'The test email could not be sent: refused', code: 'mail_test_failed' }, { status: 502 }),
            ),
        );
        renderWithProviders(<SettingsPage />);

        const panel = await screen.findByRole('region', { name: 'Test email' });
        await userEvent.type(within(panel).getByLabelText('Send a test email to'), 'me@example.com');
        await userEvent.click(within(panel).getByRole('button', { name: 'Send test email' }));

        expect(await within(panel).findByText(/refused/)).toBeInTheDocument();
    });
});
