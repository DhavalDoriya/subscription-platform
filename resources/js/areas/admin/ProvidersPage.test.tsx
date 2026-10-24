import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { ProvidersPage } from './ProvidersPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const meta = (total: number) => ({ current_page: 1, per_page: 15, total, last_page: 1 });

const base = {
    business_category: 'fitness',
    country: 'US',
    support_email: 'help@acme.test',
    currency: 'usd',
    timezone: 'UTC',
    stripe_status: 'not_started',
    rejection_reason: null,
    approved_at: null,
    created_at: '2026-10-01T10:00:00Z',
    customers_count: 3,
    owner: { id: 'o1', name: 'Olive Owner', email: 'olive@acme.test' },
};
const acme = { ...base, id: 'p1', name: 'Acme Fitness', slug: 'acme', status: 'pending' };
const beta = { ...base, id: 'p2', name: 'Beta Yoga', slug: 'beta', status: 'active', customers_count: 12 };

const requests: URL[] = [];
const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    requests.length = 0;
    server.use(
        http.get(url('/admin/providers'), ({ request }) => {
            requests.push(new URL(request.url));
            return HttpResponse.json({ data: [acme, beta], meta: meta(2) });
        }),
    );
});
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

const row = (name: string) => screen.getByRole('row', { name: new RegExp(name) });

describe('FR-AD2 providers page', () => {
    it('lists providers with status badges, subdomain and customer counts', async () => {
        renderWithProviders(<ProvidersPage />);

        expect(await screen.findByRole('cell', { name: /Acme Fitness/ })).toBeInTheDocument();
        expect(within(row('Acme Fitness')).getByText('Pending')).toBeInTheDocument();
        expect(within(row('Beta Yoga')).getByText('Active')).toBeInTheDocument();
        expect(within(row('Beta Yoga')).getByText('12')).toBeInTheDocument();
        expect(within(row('Acme Fitness')).getByText('acme')).toBeInTheDocument();
    });

    it('filters by status through the API', async () => {
        renderWithProviders(<ProvidersPage />);
        await screen.findByRole('cell', { name: /Acme Fitness/ });

        await userEvent.selectOptions(screen.getByLabelText('Status'), 'pending');

        await waitFor(() => expect(requests.at(-1)!.searchParams.get('status')).toBe('pending'));
        expect(requests.at(-1)!.searchParams.get('page')).toBe('1');
    });

    it('searches after typing stops', async () => {
        renderWithProviders(<ProvidersPage />);
        await screen.findByRole('cell', { name: /Acme Fitness/ });

        await userEvent.type(screen.getByRole('searchbox', { name: 'Search providers' }), 'gym');

        await waitFor(() => expect(requests.at(-1)!.searchParams.get('search')).toBe('gym'));
        expect(requests.filter((r) => r.searchParams.get('search')).length).toBe(1); // debounced into one request
    });

    it('sorts by a column', async () => {
        renderWithProviders(<ProvidersPage />);
        await screen.findByRole('cell', { name: /Acme Fitness/ });

        await userEvent.click(within(screen.getByRole('columnheader', { name: /Provider/ })).getByRole('button'));

        await waitFor(() => expect(requests.at(-1)!.searchParams.get('sort')).toBe('name'));
    });

    it('shows an empty state when nothing matches', async () => {
        server.use(http.get(url('/admin/providers'), () => HttpResponse.json({ data: [], meta: meta(0) })));
        renderWithProviders(<ProvidersPage />);

        expect(await screen.findByText('No providers found')).toBeInTheDocument();
    });

    it('shows the API error with a retry', async () => {
        server.use(http.get(url('/admin/providers'), () => HttpResponse.json({ message: 'Server error.' }, { status: 500 })));
        renderWithProviders(<ProvidersPage />);

        expect(await screen.findByRole('alert')).toHaveTextContent('Server error.');
        expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
    });
});

describe('FR-AD4 provider detail and lifecycle actions', () => {
    async function openDetail(name: string) {
        renderWithProviders(<ProvidersPage />);
        await screen.findByRole('cell', { name: /Acme Fitness/ });
        await userEvent.click(within(row(name)).getByRole('button', { name: /View/ }));
        return await screen.findByRole('dialog');
    }

    it('shows the owner, contact and status in a dialog', async () => {
        const dialog = await openDetail('Acme Fitness');

        expect(within(dialog).getByText('Olive Owner')).toBeInTheDocument();
        expect(within(dialog).getByText('olive@acme.test')).toBeInTheDocument();
        expect(within(dialog).getByText('help@acme.test')).toBeInTheDocument();
    });

    it('offers only the actions the lifecycle allows', async () => {
        const dialog = await openDetail('Acme Fitness'); // pending

        expect(within(dialog).getByRole('button', { name: 'Approve' })).toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: 'Reject' })).toBeInTheDocument();
        expect(within(dialog).queryByRole('button', { name: 'Suspend' })).not.toBeInTheDocument();
        expect(within(dialog).queryByRole('button', { name: 'Activate' })).not.toBeInTheDocument();
    });

    it('approves a provider and refreshes the list', async () => {
        let approved = false;
        server.use(
            http.post(url('/admin/providers/p1/approve'), () => {
                approved = true;
                return HttpResponse.json({ data: { ...acme, status: 'approved' } });
            }),
        );
        const dialog = await openDetail('Acme Fitness');
        const before = requests.length;

        await userEvent.click(within(dialog).getByRole('button', { name: 'Approve' }));

        await waitFor(() => expect(approved).toBe(true));
        await waitFor(() => expect(requests.length).toBeGreaterThan(before));
    });

    it('requires a reason to reject and shows the server validation message', async () => {
        let body: unknown;
        server.use(
            http.post(url('/admin/providers/p1/reject'), async ({ request }) => {
                body = await request.json();
                return (body as { reason?: string }).reason
                    ? HttpResponse.json({ data: { ...acme, status: 'rejected' } })
                    : HttpResponse.json({ message: 'invalid', errors: { reason: ['The reason field is required.'] } }, { status: 422 });
            }),
        );
        const dialog = await openDetail('Acme Fitness');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Reject' }));

        const rejectDialog = await screen.findByRole('dialog', { name: 'Reject provider' });
        await userEvent.click(within(rejectDialog).getByRole('button', { name: 'Reject provider' }));
        expect(await within(rejectDialog).findByText('The reason field is required.')).toBeInTheDocument();

        await userEvent.type(within(rejectDialog).getByLabelText('Reason'), 'Incomplete details');
        await userEvent.click(within(rejectDialog).getByRole('button', { name: 'Reject provider' }));

        await waitFor(() => expect(body).toEqual({ reason: 'Incomplete details' }));
        await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Reject provider' })).not.toBeInTheDocument());
    });

    it('warns that deactivating pauses billing and only acts after confirmation', async () => {
        let called = false;
        server.use(
            http.post(url('/admin/providers/p2/deactivate'), () => {
                called = true;
                return HttpResponse.json({ data: { ...beta, status: 'inactive' } });
            }),
        );
        const dialog = await openDetail('Beta Yoga'); // active
        await userEvent.click(within(dialog).getByRole('button', { name: 'Deactivate' }));

        const confirm = await screen.findByRole('alertdialog');
        expect(confirm).toHaveTextContent(/pause billing/i);
        expect(called).toBe(false);

        await userEvent.click(within(confirm).getByRole('button', { name: 'Deactivate' }));
        await waitFor(() => expect(called).toBe(true));
    });

    it('edits the slug and shows the API validation message', async () => {
        server.use(
            http.patch(url('/admin/providers/p1'), () =>
                HttpResponse.json({ message: 'invalid', errors: { slug: ['This address is already taken.'] } }, { status: 422 }),
            ),
        );
        const dialog = await openDetail('Acme Fitness');

        const slug = within(dialog).getByLabelText('Address (slug)');
        await userEvent.clear(slug);
        await userEvent.type(slug, 'beta');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }));

        expect(await within(dialog).findByText('This address is already taken.')).toBeInTheDocument();
    });
});
