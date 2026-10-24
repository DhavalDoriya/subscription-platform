import { cleanup, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { useAuthStore } from '@/lib/auth';
import { renderWithProviders } from '@/test/render';
import { StorefrontView } from '../StorefrontView';
import type { StorefrontPlan, StorefrontProvider } from '../types';
import { CheckoutProvider } from './CheckoutProvider';

const url = (p: string) => `http://localhost/api/v1${p}`;
const user = { id: 'u1', name: 'Olive', email: 'olive@example.com', email_verified_at: null, avatar_url: null, roles: ['customer' as const] };
const provider: StorefrontProvider = {
    name: 'Acme Gym',
    slug: 'acme',
    support_email: 'help@acme.test',
    currency: 'usd',
    branding: { primary_color: null },
    legal: { terms_url: null, privacy_url: null, refund_url: null },
};
const monthly = { id: 'pr1', amount: 2000, currency: 'usd', interval: 'month', interval_count: 1 };
const yearly = { id: 'pr2', amount: 20000, currency: 'usd', interval: 'year', interval_count: 1 };
const pro: StorefrontPlan = { id: 'pl1', name: 'Pro', description: null, features: [], trial_days: 0, prices: [monthly] };
const blocks = [{ id: 'b1', type: 'plans' as const, data: { heading: 'Pricing' } }];

const stripe = vi.hoisted(() => ({ confirmPayment: vi.fn(), confirmSetup: vi.fn() }));
vi.mock('@/lib/stripe', () => ({ stripeFor: vi.fn(() => Promise.resolve({})) }));
vi.mock('@stripe/react-stripe-js', () => ({
    Elements: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    PaymentElement: () => <div>card-fields</div>,
    useStripe: () => stripe,
    useElements: () => ({}),
}));

const started = (type: 'payment' | 'setup' = 'payment') => ({
    subscription: { id: 's1', status: type === 'setup' ? 'trialing' : 'incomplete' },
    client_secret: `${type === 'setup' ? 'seti' : 'pi'}_1_secret`,
    client_secret_type: type,
    stripe_account: 'acct_1',
    publishable_key: 'pk_test_1',
});
const session = { access_token: 'tok', token_type: 'bearer', expires_in: 900, user };
const mySubscription = (status: string) => ({ data: [{ id: 's1', status }], meta: { current_page: 1, per_page: 100, total: 1, last_page: 1 } });

let checkoutBody: unknown = null;
const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    checkoutBody = null;
    stripe.confirmPayment.mockReset().mockResolvedValue({ paymentIntent: { status: 'succeeded' } });
    stripe.confirmSetup.mockReset().mockResolvedValue({ setupIntent: { status: 'succeeded' } });
    useAuthStore.getState().clear();
    server.use(
        http.post(
            url('/customer/subscriptions'),
            async ({ request }) => ((checkoutBody = await request.json()), HttpResponse.json({ data: started() }, { status: 201 })),
        ),
        http.get(url('/customer/subscriptions'), () => HttpResponse.json(mySubscription('active'))),
    );
});
afterEach(() => {
    cleanup();
    useAuthStore.getState().clear();
    server.resetHandlers();
});
afterAll(() => server.close());

function renderStorefront(plans: StorefrontPlan[] = [pro]) {
    return renderWithProviders(
        <CheckoutProvider providerName={provider.name}>
            <StorefrontView provider={provider} blocks={blocks} plans={plans} />
        </CheckoutProvider>,
    );
}

const signedIn = () => useAuthStore.getState().setSession(user, 'tok');

describe('FR-C1 storefront checkout: joining', () => {
    it('lets a signed-in customer continue with one click, without registering again', async () => {
        signedIn();
        renderStorefront();

        await userEvent.click(screen.getByRole('button', { name: 'Subscribe' }));
        const dialog = await screen.findByRole('dialog', { name: /Subscribe to Pro/ });

        expect(within(dialog).queryByLabelText('Password')).not.toBeInTheDocument();
        await userEvent.click(within(dialog).getByRole('button', { name: 'Continue as Olive' }));

        expect(await within(dialog).findByText('card-fields')).toBeInTheDocument();
        expect(checkoutBody).toEqual({ price_id: 'pr1' });
    });

    it('asks a visitor to sign in first, then continues as them', async () => {
        server.use(http.post(url('/auth/login'), () => HttpResponse.json({ data: session })));
        renderStorefront();

        await userEvent.click(screen.getByRole('button', { name: 'Subscribe' }));
        const dialog = await screen.findByRole('dialog', { name: /Sign in to subscribe/ });
        await userEvent.type(within(dialog).getByLabelText('Email'), 'olive@example.com');
        await userEvent.type(within(dialog).getByLabelText('Password'), 'Password123!');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Sign in' }));

        expect(await screen.findByRole('button', { name: 'Continue as Olive' })).toBeInTheDocument();
        expect(checkoutBody).toBeNull(); // signing in alone never subscribes
    });

    it('lets a new visitor create an account right there', async () => {
        let registered: unknown = null;
        server.use(
            http.post(
                url('/auth/register'),
                async ({ request }) => ((registered = await request.json()), HttpResponse.json({ data: session }, { status: 201 })),
            ),
        );
        renderStorefront();

        await userEvent.click(screen.getByRole('button', { name: 'Subscribe' }));
        const dialog = await screen.findByRole('dialog');
        await userEvent.click(within(dialog).getByRole('tab', { name: 'Create account' }));
        await userEvent.type(within(dialog).getByLabelText('Full name'), 'Olive');
        await userEvent.type(within(dialog).getByLabelText('Email'), 'olive@example.com');
        await userEvent.type(within(dialog).getByLabelText('Password'), 'Password123!');
        await userEvent.type(within(dialog).getByLabelText('Confirm password'), 'Password123!');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Create account' }));

        expect(await screen.findByRole('button', { name: 'Continue as Olive' })).toBeInTheDocument();
        expect(registered).toMatchObject({ name: 'Olive', email: 'olive@example.com' });
    });

    it('offers to switch account when it is not them', async () => {
        let loggedOut = false;
        server.use(http.post(url('/auth/logout'), () => ((loggedOut = true), HttpResponse.json({ data: null }))));
        signedIn();
        renderStorefront();

        await userEvent.click(screen.getByRole('button', { name: 'Subscribe' }));
        await userEvent.click(await screen.findByRole('button', { name: 'Not Olive? Use a different account' }));

        expect(await screen.findByLabelText('Password')).toBeInTheDocument();
        expect(loggedOut).toBe(true);
    });

    it('shows why joining is not possible instead of failing silently', async () => {
        server.use(
            http.post(url('/customer/subscriptions'), () =>
                HttpResponse.json({ message: 'You already have a subscription to this plan.', code: 'already_subscribed' }, { status: 409 }),
            ),
        );
        signedIn();
        renderStorefront();

        await userEvent.click(screen.getByRole('button', { name: 'Subscribe' }));
        await userEvent.click(await screen.findByRole('button', { name: 'Continue as Olive' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('You already have a subscription to this plan.');
        expect(screen.queryByText('card-fields')).not.toBeInTheDocument();
    });
});

describe('FR-C1 storefront checkout: paying', () => {
    it('confirms the payment, waits for the subscription to go live and then welcomes the customer', async () => {
        signedIn();
        renderStorefront();

        await userEvent.click(screen.getByRole('button', { name: 'Subscribe' }));
        await userEvent.click(await screen.findByRole('button', { name: 'Continue as Olive' }));
        await userEvent.click(await screen.findByRole('button', { name: 'Pay $20.00' }));

        expect(await screen.findByRole('heading', { name: /You're subscribed to Pro/ })).toBeInTheDocument();
        expect(stripe.confirmPayment).toHaveBeenCalled();
        expect(screen.getByRole('link', { name: 'Manage your subscription' })).toHaveAttribute('href', expect.stringMatching(/\/account$/));
    });

    it('starts a free trial with only a card on file', async () => {
        server.use(
            http.post(url('/customer/subscriptions'), () => HttpResponse.json({ data: started('setup') }, { status: 201 })),
            http.get(url('/customer/subscriptions'), () => HttpResponse.json(mySubscription('trialing'))),
        );
        signedIn();
        renderStorefront([{ ...pro, trial_days: 14 }]);

        await userEvent.click(screen.getByRole('button', { name: 'Subscribe' }));
        const dialog = await screen.findByRole('dialog');
        expect(dialog).toHaveTextContent('14-day free trial');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Continue as Olive' }));
        await userEvent.click(await screen.findByRole('button', { name: 'Start free trial' }));

        expect(await screen.findByRole('heading', { name: /You're subscribed to Pro/ })).toBeInTheDocument();
        expect(stripe.confirmSetup).toHaveBeenCalled();
        expect(stripe.confirmPayment).not.toHaveBeenCalled();
    });

    it('keeps the customer on the payment step when the card is declined', async () => {
        stripe.confirmPayment.mockResolvedValue({ error: { message: 'Your card was declined.' } });
        signedIn();
        renderStorefront();

        await userEvent.click(screen.getByRole('button', { name: 'Subscribe' }));
        await userEvent.click(await screen.findByRole('button', { name: 'Continue as Olive' }));
        await userEvent.click(await screen.findByRole('button', { name: 'Pay $20.00' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Your card was declined.');
        expect(screen.queryByRole('heading', { name: /You're subscribed/ })).not.toBeInTheDocument();
    });

    it('says the subscription is still being confirmed if it does not go live in time', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        server.use(http.get(url('/customer/subscriptions'), () => HttpResponse.json(mySubscription('incomplete'))));
        signedIn();
        renderStorefront();

        await userEvent.click(screen.getByRole('button', { name: 'Subscribe' }));
        await userEvent.click(await screen.findByRole('button', { name: 'Continue as Olive' }));
        await userEvent.click(await screen.findByRole('button', { name: 'Pay $20.00' }));
        expect(await screen.findByText(/Confirming your payment/)).toBeInTheDocument();

        await vi.advanceTimersByTimeAsync(30_000);
        await waitFor(() => expect(screen.getByRole('heading', { name: /Payment received/ })).toBeInTheDocument());
        vi.useRealTimers();
    });
});

describe('FR-C1 storefront plan cards', () => {
    it('offers one button per price when a plan has several', () => {
        renderStorefront([{ ...pro, prices: [monthly, yearly] }]);

        expect(screen.getByRole('button', { name: 'Subscribe · $20.00 / month' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Subscribe · $200.00 / year' })).toBeEnabled();
    });

    it('keeps the button disabled where checkout is not available, such as the editor preview', () => {
        renderWithProviders(<StorefrontView provider={provider} blocks={blocks} plans={[pro]} embedded />);

        expect(screen.getByRole('button', { name: 'Subscribe' })).toBeDisabled();
    });
});
