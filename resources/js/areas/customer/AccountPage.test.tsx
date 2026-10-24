import { cleanup, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { useAuthStore, type Role } from '@/lib/auth';
import { renderWithProviders } from '@/test/render';
import { AccountPage } from './AccountPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const meta = (total: number) => ({ current_page: 1, per_page: 15, total, last_page: 1 });
const acme = { id: 'p1', name: 'Acme Fitness', slug: 'acme' };
const pro = {
    id: 's1',
    status: 'active',
    has_access: true,
    plan: { id: 'pl1', name: 'Pro' },
    provider: acme,
    amount: 1900,
    currency: 'usd',
    interval: 'month',
    interval_count: 1,
    cancel_at_period_end: false,
    current_period_end: '2026-11-20T12:00:00Z',
    trial_ends_at: null,
    canceled_at: null,
    created_at: '2026-10-01T00:00:00Z',
};
const ending = { ...pro, id: 's2', plan: { id: 'pl2', name: 'Basic' }, cancel_at_period_end: true };
const card = { id: 'pm1', brand: 'visa', last4: '4242', exp_month: 12, exp_year: 2031, is_default: true, provider: acme };
const spare = { ...card, id: 'pm2', last4: '1111', is_default: false };
const invoice = {
    id: 'i1',
    number: 'ACME-0001',
    status: 'paid',
    subscription_id: 's1',
    currency: 'usd',
    amount_due: 1900,
    amount_paid: 1900,
    hosted_invoice_url: 'https://pay.stripe.test/i/1',
    invoice_pdf_url: 'https://pay.stripe.test/i/1.pdf',
    period_start: null,
    period_end: null,
    paid_at: '2026-10-01T00:00:00Z',
    created_at: '2026-10-01T00:00:00Z',
    provider: acme,
};

const stripe = vi.hoisted(() => ({ confirmSetup: vi.fn() }));
vi.mock('@/lib/stripe', () => ({ stripeFor: vi.fn(() => Promise.resolve({})) }));
vi.mock('@stripe/react-stripe-js', () => ({
    Elements: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    PaymentElement: () => <div>card-fields</div>,
    useStripe: () => stripe,
    useElements: () => ({}),
}));

let subscriptions: unknown[] = [];
let methods: unknown[] = [];
const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    subscriptions = [pro, ending];
    methods = [card, spare];
    stripe.confirmSetup.mockReset();
    server.use(
        http.get(url('/customer/subscriptions'), () => HttpResponse.json({ data: subscriptions, meta: meta(subscriptions.length) })),
        http.get(url('/customer/invoices'), () => HttpResponse.json({ data: [invoice], meta: meta(1) })),
        http.get(url('/customer/payment-methods'), () => HttpResponse.json({ data: methods })),
        http.get(url('/customer/payment-issues'), () => HttpResponse.json({ data: [] })),
    );
});
afterEach(() => {
    cleanup(); // unmount before the session is cleared, or the page re-renders with no user
    useAuthStore.getState().clear();
    server.resetHandlers();
});
afterAll(() => server.close());

function setup(roles: Role[] = ['customer']) {
    useAuthStore.getState().setSession({ id: 'u1', name: 'Olive', email: 'olive@example.com', email_verified_at: null, avatar_url: null, roles }, 't');
    return renderWithProviders(<AccountPage />);
}

const subscription = (plan: string) => screen.getByRole('article', { name: new RegExp(plan) });

describe('account page links', () => {
    it('offers a customer the way to become a provider', async () => {
        setup(['customer']);
        expect(await screen.findByRole('link', { name: 'Become a provider' })).toHaveAttribute('href', '/become-a-provider');
        expect(screen.queryByRole('link', { name: 'Provider dashboard' })).not.toBeInTheDocument();
    });

    it('sends a provider owner to their dashboard instead', async () => {
        setup(['customer', 'provider_owner']);
        expect(await screen.findByRole('link', { name: 'Provider dashboard' })).toHaveAttribute('href', '/dashboard');
        expect(screen.queryByRole('link', { name: 'Become a provider' })).not.toBeInTheDocument();
    });
});

describe('FR-CP1 my subscriptions', () => {
    it('shows each subscription with its provider, price, status and next billing date', async () => {
        setup();

        const card = within(await screen.findByRole('article', { name: /Pro/ }));
        expect(card.getByText('Acme Fitness')).toBeInTheDocument();
        expect(card.getByText('$19.00 / month')).toBeInTheDocument();
        expect(card.getByText('Active')).toBeInTheDocument();
        expect(card.getByText(/Renews Nov 20, 2026/)).toBeInTheDocument();
        expect(within(subscription('Basic')).getByText(/Ends Nov 20, 2026/)).toBeInTheDocument();
    });

    it('says so when there are no subscriptions yet', async () => {
        subscriptions = [];
        setup();

        expect(await screen.findByText(/haven't subscribed to anything yet/i)).toBeInTheDocument();
    });

    it('cancels at the period end after confirmation', async () => {
        let body: unknown = null;
        server.use(
            http.post(
                url('/customer/subscriptions/s1/cancel'),
                async ({ request }) => ((body = await request.json()), HttpResponse.json({ data: { ...pro, cancel_at_period_end: true } })),
            ),
        );
        setup();

        await userEvent.click(within(await screen.findByRole('article', { name: /Pro/ })).getByRole('button', { name: 'Cancel subscription' }));
        const dialog = await screen.findByRole('alertdialog');
        expect(dialog).toHaveTextContent('Nov 20, 2026');
        expect(body).toBeNull();
        await userEvent.click(within(dialog).getByRole('button', { name: 'Cancel at period end' }));

        await waitFor(() => expect(body).toEqual({ when: 'period_end' }));
    });

    it('can cancel immediately instead', async () => {
        let body: unknown = null;
        server.use(
            http.post(
                url('/customer/subscriptions/s1/cancel'),
                async ({ request }) => ((body = await request.json()), HttpResponse.json({ data: { ...pro, status: 'canceled' } })),
            ),
        );
        setup();

        await userEvent.click(within(await screen.findByRole('article', { name: /Pro/ })).getByRole('button', { name: 'Cancel subscription' }));
        await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Cancel now' }));

        await waitFor(() => expect(body).toEqual({ when: 'now' }));
    });

    it('resumes a subscription that is set to end, and only that one', async () => {
        let resumed = false;
        server.use(
            http.post(
                url('/customer/subscriptions/s2/resume'),
                () => ((resumed = true), HttpResponse.json({ data: { ...ending, cancel_at_period_end: false } })),
            ),
        );
        setup();
        await screen.findByRole('article', { name: /Basic/ });

        expect(within(subscription('Pro')).queryByRole('button', { name: 'Resume' })).not.toBeInTheDocument();
        await userEvent.click(within(subscription('Basic')).getByRole('button', { name: 'Resume' }));

        await waitFor(() => expect(resumed).toBe(true));
    });

    it('previews the cost of a plan change before asking to confirm it', async () => {
        let body: unknown = null;
        server.use(
            http.get(url('/customer/subscriptions/s1/options'), () =>
                HttpResponse.json({
                    data: [{ price_id: 'pr9', plan_id: 'pl9', plan_name: 'Max', amount: 5000, currency: 'usd', interval: 'month', interval_count: 1 }],
                }),
            ),
            http.post(url('/customer/subscriptions/s1/change/preview'), () =>
                HttpResponse.json({
                    data: { timing: 'immediate', credit: 1000, charge: 2500, net: 1500, currency: 'usd', effective_at: '2026-11-05T00:00:00Z' },
                }),
            ),
            http.post(
                url('/customer/subscriptions/s1/change'),
                async ({ request }) => ((body = await request.json()), HttpResponse.json({ data: { ...pro, amount: 5000, plan: { id: 'pl9', name: 'Max' } } })),
            ),
        );
        setup();

        await userEvent.click(within(await screen.findByRole('article', { name: /Pro/ })).getByRole('button', { name: 'Change plan' }));
        const dialog = await screen.findByRole('dialog');
        await userEvent.selectOptions(await within(dialog).findByLabelText('New plan'), 'pr9');

        expect(await within(dialog).findByText(/Credit for unused time/)).toBeInTheDocument();
        expect(within(dialog).getByText('-$10.00')).toBeInTheDocument();
        expect(within(dialog).getByText('$25.00')).toBeInTheDocument();
        expect(within(dialog).getByText(/Due today/)).toBeInTheDocument();
        expect(within(dialog).getByText('$15.00')).toBeInTheDocument();

        await userEvent.click(within(dialog).getByRole('button', { name: 'Confirm change' }));
        await waitFor(() => expect(body).toEqual({ price_id: 'pr9', timing: 'immediate' }));
    });

    it('offers no plan change or cancel for a subscription that is already over', async () => {
        subscriptions = [{ ...pro, status: 'canceled' }];
        setup();

        const card = within(await screen.findByRole('article', { name: /Pro/ }));
        expect(card.queryByRole('button', { name: 'Cancel subscription' })).not.toBeInTheDocument();
        expect(card.queryByRole('button', { name: 'Change plan' })).not.toBeInTheDocument();
    });
});

describe('FR-CP2 billing history', () => {
    it('lists invoices with a link to the hosted copy and a PDF', async () => {
        setup();

        const row = within(await screen.findByRole('row', { name: /ACME-0001/ }));
        expect(row.getByText('$19.00')).toBeInTheDocument();
        expect(row.getByText('Paid')).toBeInTheDocument();
        expect(row.getByRole('link', { name: 'View ACME-0001' })).toHaveAttribute('href', 'https://pay.stripe.test/i/1');
        expect(row.getByRole('link', { name: 'PDF ACME-0001' })).toHaveAttribute('href', 'https://pay.stripe.test/i/1.pdf');
    });
});

describe('FR-C7 payment methods', () => {
    it('shows saved cards by provider with the default marked', async () => {
        setup();

        const item = within(await screen.findByRole('listitem', { name: /4242/ }));
        expect(item.getByText(/Visa/)).toBeInTheDocument();
        expect(item.getByText('Default')).toBeInTheDocument();
        expect(item.getByText('Expires 12/2031')).toBeInTheDocument();
        expect(within(screen.getByRole('listitem', { name: /1111/ })).queryByText('Default')).not.toBeInTheDocument();
    });

    it('makes another card the default', async () => {
        let made = false;
        server.use(http.post(url('/customer/payment-methods/pm2/default'), () => ((made = true), HttpResponse.json({ data: { ...spare, is_default: true } }))));
        setup();

        await userEvent.click(within(await screen.findByRole('listitem', { name: /1111/ })).getByRole('button', { name: 'Make default' }));

        await waitFor(() => expect(made).toBe(true));
    });

    it('removes a card after confirmation and explains when it cannot be removed', async () => {
        server.use(
            http.delete(url('/customer/payment-methods/pm2'), () =>
                HttpResponse.json({ message: 'Add another card before removing this one.', code: 'payment_method_in_use' }, { status: 409 }),
            ),
        );
        setup();

        await userEvent.click(within(await screen.findByRole('listitem', { name: /1111/ })).getByRole('button', { name: 'Remove' }));
        await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Remove card' }));

        expect(await screen.findByText('Add another card before removing this one.')).toBeInTheDocument();
    });

    it('adds a card for a provider through Stripe', async () => {
        let asked: unknown = null;
        server.use(
            http.post(url('/customer/payment-methods/setup-intent'), async ({ request }) => {
                asked = await request.json();
                return HttpResponse.json({ data: { client_secret: 'seti_1_secret', stripe_account: 'acct_1', publishable_key: 'pk_test_1' } });
            }),
        );
        stripe.confirmSetup.mockResolvedValue({ setupIntent: { status: 'succeeded' } });
        setup();

        await userEvent.click(await screen.findByRole('button', { name: 'Add a card for Acme Fitness' }));
        const dialog = await screen.findByRole('dialog');
        expect(await within(dialog).findByText('card-fields')).toBeInTheDocument();
        expect(asked).toEqual({ provider_id: 'p1' });

        await userEvent.click(within(dialog).getByRole('button', { name: 'Save card' }));
        await waitFor(() => expect(stripe.confirmSetup).toHaveBeenCalled());
    });
});
