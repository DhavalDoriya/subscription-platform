import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { PlansPage } from './PlansPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const meta = (total: number) => ({ current_page: 1, per_page: 15, total, last_page: 1 });
const price = { id: 'pr1', amount: 1900, currency: 'usd', interval: 'month', interval_count: 1, status: 'active', synced: false };
const draft = {
    id: 'pl1',
    name: 'Starter',
    description: 'For small teams',
    features: ['One seat', 'Email support'],
    status: 'draft',
    visibility: 'public',
    trial_days: 14,
    sort_order: 0,
    prices: [price],
    created_at: '2026-10-01T00:00:00Z',
};
const live = { ...draft, id: 'pl2', name: 'Pro', status: 'active', prices: [{ ...price, id: 'pr2', amount: 4900, synced: true }] };
const provider = {
    id: 'p1',
    name: 'Acme',
    slug: 'acme',
    status: 'active',
    currency: 'usd',
    stripe_status: 'enabled',
    support_email: 'a@b.test',
    country: 'US',
    timezone: 'UTC',
    business_category: 'x',
    rejection_reason: null,
    approved_at: null,
    created_at: '',
    branding: { primary_color: null },
    legal: { terms_url: null, privacy_url: null, refund_url: null },
    tax_enabled: false,
};

const requests: URL[] = [];
const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    requests.length = 0;
    server.use(
        http.get(url('/provider'), () => HttpResponse.json({ data: provider })),
        http.get(url('/public/config'), () =>
            HttpResponse.json({ data: { platform_name: 'P', default_currency: 'usd', allowed_currencies: ['usd'], allowed_intervals: ['month', 'year'] } }),
        ),
        http.get(url('/provider/plans'), ({ request }) => {
            requests.push(new URL(request.url));
            return HttpResponse.json({ data: [draft, live], meta: meta(2) });
        }),
    );
});
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

const row = (name: string) => screen.getByRole('row', { name: new RegExp(name) });

describe('FR-PL1 plans page', () => {
    it('lists plans with status, price summary and trial', async () => {
        renderWithProviders(<PlansPage />);

        expect(await screen.findByRole('cell', { name: /Starter/ })).toBeInTheDocument();
        expect(within(row('Starter')).getByText('Draft')).toBeInTheDocument();
        expect(within(row('Starter')).getByText('$19.00 / month')).toBeInTheDocument();
        expect(within(row('Pro')).getByText('Live')).toBeInTheDocument();
        expect(within(row('Pro')).getByText('$49.00 / month')).toBeInTheDocument();
    });

    it('filters by status through the API', async () => {
        renderWithProviders(<PlansPage />);
        await screen.findByRole('cell', { name: /Starter/ });

        await userEvent.selectOptions(screen.getByLabelText('Status'), 'draft');

        await waitFor(() => expect(requests.at(-1)!.searchParams.get('status')).toBe('draft'));
    });

    it('shows an empty state with the way forward', async () => {
        server.use(http.get(url('/provider/plans'), () => HttpResponse.json({ data: [], meta: meta(0) })));
        renderWithProviders(<PlansPage />);

        expect(await screen.findByText('No plans yet')).toBeInTheDocument();
    });
});

describe('FR-PL1 creating a plan', () => {
    it('creates a draft plan from the form, one feature per line', async () => {
        let body: unknown;
        server.use(
            http.post(url('/provider/plans'), async ({ request }) => {
                body = await request.json();
                return HttpResponse.json({ data: { ...draft, id: 'new' } }, { status: 201 });
            }),
        );
        renderWithProviders(<PlansPage />);
        await screen.findByRole('cell', { name: /Starter/ });

        await userEvent.click(screen.getByRole('button', { name: 'New plan' }));
        const dialog = await screen.findByRole('dialog', { name: 'New plan' });
        await userEvent.type(within(dialog).getByLabelText('Name'), 'Team');
        await userEvent.type(within(dialog).getByLabelText('Description'), 'For teams');
        await userEvent.type(within(dialog).getByLabelText('Features'), 'Ten seats{enter}Phone support');
        await userEvent.clear(within(dialog).getByLabelText('Free trial (days)'));
        await userEvent.type(within(dialog).getByLabelText('Free trial (days)'), '7');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Create plan' }));

        await waitFor(() =>
            expect(body).toEqual({ name: 'Team', description: 'For teams', features: ['Ten seats', 'Phone support'], trial_days: 7, visibility: 'public' }),
        );
    });

    it('shows API validation errors next to the field', async () => {
        server.use(
            http.post(url('/provider/plans'), () =>
                HttpResponse.json({ message: 'invalid', errors: { name: ['The name field is required.'] } }, { status: 422 }),
            ),
        );
        renderWithProviders(<PlansPage />);
        await screen.findByRole('cell', { name: /Starter/ });

        await userEvent.click(screen.getByRole('button', { name: 'New plan' }));
        const dialog = await screen.findByRole('dialog', { name: 'New plan' });
        await userEvent.click(within(dialog).getByRole('button', { name: 'Create plan' }));

        expect(await within(dialog).findByText('The name field is required.')).toBeInTheDocument();
    });

    it('explains the plan limit instead of failing silently', async () => {
        server.use(
            http.post(url('/provider/plans'), () =>
                HttpResponse.json({ message: 'You have reached the maximum number of plans.', code: 'plan_limit_reached' }, { status: 409 }),
            ),
        );
        renderWithProviders(<PlansPage />);
        await screen.findByRole('cell', { name: /Starter/ });

        await userEvent.click(screen.getByRole('button', { name: 'New plan' }));
        const dialog = await screen.findByRole('dialog', { name: 'New plan' });
        await userEvent.type(within(dialog).getByLabelText('Name'), 'One too many');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Create plan' }));

        expect(await within(dialog).findByRole('alert')).toHaveTextContent('maximum number of plans');
    });
});

describe('FR-PL4 publishing and archiving', () => {
    it('publishes a draft', async () => {
        let published = false;
        server.use(http.post(url('/provider/plans/pl1/publish'), () => ((published = true), HttpResponse.json({ data: { ...draft, status: 'active' } }))));
        renderWithProviders(<PlansPage />);
        await screen.findByRole('cell', { name: /Starter/ });

        await userEvent.click(within(row('Starter')).getByRole('button', { name: /Publish/ }));

        await waitFor(() => expect(published).toBe(true));
    });

    it('tells the owner to finish Stripe when publishing is refused', async () => {
        server.use(
            http.post(url('/provider/plans/pl1/publish'), () =>
                HttpResponse.json(
                    { message: 'Finish connecting Stripe before publishing plans or adding prices to a live plan.', code: 'stripe_not_enabled' },
                    { status: 409 },
                ),
            ),
        );
        renderWithProviders(<PlansPage />);
        await screen.findByRole('cell', { name: /Starter/ });

        await userEvent.click(within(row('Starter')).getByRole('button', { name: /Publish/ }));

        expect(await screen.findByText(/Finish connecting Stripe/)).toBeInTheDocument();
    });

    it('archives a live plan only after explaining what it does', async () => {
        let archived = false;
        server.use(http.post(url('/provider/plans/pl2/archive'), () => ((archived = true), HttpResponse.json({ data: { ...live, status: 'archived' } }))));
        renderWithProviders(<PlansPage />);
        await screen.findByRole('cell', { name: /Pro/ });

        await userEvent.click(within(row('Pro')).getByRole('button', { name: /Archive/ }));
        const confirm = await screen.findByRole('alertdialog');
        expect(confirm).toHaveTextContent(/existing subscribers/i);
        expect(archived).toBe(false);
        await userEvent.click(within(confirm).getByRole('button', { name: 'Archive' }));

        await waitFor(() => expect(archived).toBe(true));
    });

    it('deletes a draft but offers no delete for a live plan', async () => {
        let deleted = false;
        server.use(http.delete(url('/provider/plans/pl1'), () => ((deleted = true), new HttpResponse(null, { status: 204 }))));
        renderWithProviders(<PlansPage />);
        await screen.findByRole('cell', { name: /Starter/ });

        expect(within(row('Pro')).queryByRole('button', { name: /Delete/ })).not.toBeInTheDocument();
        await userEvent.click(within(row('Starter')).getByRole('button', { name: /Delete/ }));
        await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Delete' }));

        await waitFor(() => expect(deleted).toBe(true));
    });
});

describe('FR-PL2 prices', () => {
    async function openEditor(name: string) {
        renderWithProviders(<PlansPage />);
        await screen.findByRole('cell', { name: /Starter/ });
        await userEvent.click(within(row(name)).getByRole('button', { name: /Edit/ }));
        return await screen.findByRole('dialog', { name: new RegExp(`Edit ${name}`) });
    }

    it("shows the plan's prices and adds one, converting what is typed to minor units", async () => {
        let body: unknown;
        server.use(
            http.post(url('/provider/plans/pl1/prices'), async ({ request }) => {
                body = await request.json();
                return HttpResponse.json({ data: { ...price, id: 'pr9', amount: 19000, interval: 'year' } }, { status: 201 });
            }),
        );
        const dialog = await openEditor('Starter');
        expect(within(dialog).getByText('$19.00 / month')).toBeInTheDocument();

        await userEvent.type(within(dialog).getByLabelText('Amount (USD)'), '190');
        await userEvent.selectOptions(within(dialog).getByLabelText('Billed every'), 'year');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Add price' }));

        await waitFor(() => expect(body).toEqual({ amount: 19000, currency: 'usd', interval: 'year', interval_count: 1 }));
    });

    it('rejects an amount that is not a valid money value before calling the API', async () => {
        let called = false;
        server.use(http.post(url('/provider/plans/pl1/prices'), () => ((called = true), HttpResponse.json({ data: price }, { status: 201 }))));
        const dialog = await openEditor('Starter');

        await userEvent.type(within(dialog).getByLabelText('Amount (USD)'), '19.999');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Add price' }));

        expect(await within(dialog).findByText(/valid amount/i)).toBeInTheDocument();
        expect(called).toBe(false);
    });

    it('removes a price', async () => {
        let removed = false;
        server.use(http.delete(url('/provider/plans/pl1/prices/pr1'), () => ((removed = true), new HttpResponse(null, { status: 204 }))));
        const dialog = await openEditor('Starter');

        await userEvent.click(within(dialog).getByRole('button', { name: /Remove \$19\.00 \/ month/ }));

        await waitFor(() => expect(removed).toBe(true));
    });

    it('warns that changing a live price replaces it', async () => {
        const dialog = await openEditor('Pro');

        expect(within(dialog).getByText(/replaces the price/i)).toBeInTheDocument();
    });
});
