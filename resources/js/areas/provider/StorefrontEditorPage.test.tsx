import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { StorefrontEditorPage } from './StorefrontEditorPage';

const nav = vi.hoisted(() => ({ openInNewTab: vi.fn() }));
vi.mock('@/lib/navigate', () => ({ redirectTo: vi.fn(), openInNewTab: nav.openInNewTab }));

const url = (p: string) => `http://localhost/api/v1${p}`;
const provider = {
    id: 'p1',
    name: 'Acme Gym',
    slug: 'acme',
    status: 'active',
    currency: 'usd',
    stripe_status: 'enabled',
    support_email: 'help@acme.test',
    country: 'US',
    timezone: 'UTC',
    business_category: 'fitness',
    rejection_reason: null,
    approved_at: null,
    created_at: '',
    branding: { primary_color: null },
    legal: { terms_url: null, privacy_url: null, refund_url: null },
    tax_enabled: false,
};
const hero = {
    id: 'b1',
    type: 'hero',
    visible: true,
    data: { headline: 'Train smarter', subheadline: 'Plans for everyone', button_label: 'Join', button_url: 'https://acme.test/join' },
};
const about = { id: 'b2', type: 'about', visible: true, data: { heading: 'About us', body: '<p>We are a gym.</p>' } };
const draft = (blocks: unknown[], extra: object = {}) => ({
    seo: { title: 'Acme', description: '' },
    blocks,
    published_at: null,
    has_unpublished_changes: false,
    ...extra,
});

const calls: { method: string; path: string; body?: any }[] = []; // eslint-disable-line @typescript-eslint/no-explicit-any
const server = setupServer();
let current = draft([hero, about]);

beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    calls.length = 0;
    nav.openInNewTab.mockClear();
    current = draft([hero, about]);
    server.use(
        http.get(url('/provider'), () => HttpResponse.json({ data: provider })),
        http.get(url('/provider/plans'), () => HttpResponse.json({ data: [], meta: { current_page: 1, per_page: 50, total: 0, last_page: 1 } })),
        http.get(url('/provider/storefront'), () => HttpResponse.json({ data: current })),
        http.put(url('/provider/storefront'), async ({ request }) => {
            const body = (await request.json()) as { seo: object; blocks: any[] }; // eslint-disable-line @typescript-eslint/no-explicit-any
            calls.push({ method: 'PUT', path: '/provider/storefront', body });
            current = draft(
                body.blocks.map((b, i) => ({ ...b, id: b.id ?? `new-${i}` })),
                { seo: body.seo },
            );
            return HttpResponse.json({ data: current });
        }),
    );
});
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

const card = (label: RegExp) => screen.getByRole('listitem', { name: label });
const edit = (name: string) => userEvent.click(screen.getByRole('button', { name: `Edit ${name}` }));
const form = () => screen.getByRole('region', { name: 'Section editor' });
const addSection = async (name: RegExp) => {
    await userEvent.click(screen.getByRole('button', { name: 'Add section' }));
    await userEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name }));
};
const types = () =>
    within(screen.getByRole('list', { name: 'Sections' }))
        .getAllByRole('listitem')
        .map((li) => li.getAttribute('aria-label'));

describe('FR-S2 storefront editor', () => {
    it('lists the draft blocks in order with a summary', async () => {
        renderWithProviders(<StorefrontEditorPage />);

        expect(await screen.findByRole('listitem', { name: /Hero block/ })).toBeInTheDocument();
        expect(types()).toEqual(['Hero block', 'About block']);
        expect(within(card(/Hero block/)).getByText('Train smarter')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Edit Search and sharing' })).toBeInTheDocument();
    });

    it('shows the section list first and opens one form at a time', async () => {
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });
        expect(screen.queryByLabelText('Headline')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Page title')).not.toBeInTheDocument();

        await edit('Hero');

        expect(within(form()).getByRole('heading', { name: 'Hero' })).toBeInTheDocument();
        expect(within(form()).getByLabelText('Headline')).toHaveValue('Train smarter');
        expect(screen.queryByRole('list', { name: 'Sections' })).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Heading')).not.toBeInTheDocument(); // the About form stays closed

        await userEvent.click(screen.getByRole('button', { name: 'Back to sections' }));

        expect(screen.queryByLabelText('Headline')).not.toBeInTheDocument();
        expect(types()).toEqual(['Hero block', 'About block']);
    });

    it('opens the search and sharing form from its own entry', async () => {
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });

        await edit('Search and sharing');

        expect(within(form()).getByRole('heading', { name: 'Search and sharing' })).toBeInTheDocument();
        expect(within(form()).getByLabelText('Page title')).toHaveValue('Acme');
        expect(within(form()).getByLabelText('Share image')).toBeInTheDocument();
    });

    it('invites the owner to add a first block when the page is empty', async () => {
        current = draft([]);
        renderWithProviders(<StorefrontEditorPage />);

        expect(await screen.findByText(/no sections yet/i)).toBeInTheDocument();
    });

    it('adds a block of the chosen type, opens it for editing and shows it in the preview', async () => {
        current = draft([]);
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByText(/no sections yet/i);

        await addSection(/^Hero/);

        expect(within(form()).getByLabelText('Headline')).toBeInTheDocument();
        expect(within(screen.getByRole('region', { name: 'Live preview' })).getByRole('heading', { level: 1 })).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Back to sections' }));
        expect(types()).toEqual(['Hero block']);
    });

    it('lists every section type with a short description in the add dialog', async () => {
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });

        await userEvent.click(screen.getByRole('button', { name: 'Add section' }));
        const dialog = await screen.findByRole('dialog');

        expect(
            within(dialog)
                .getAllByRole('button')
                .filter((b) => b.dataset.sectionType),
        ).toHaveLength(10);
        expect(within(dialog).getByText('Headline, button and image at the top of the page')).toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: /^FAQ/ })).toBeInTheDocument();
    });

    it('updates the live preview as you type', async () => {
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });

        await edit('Hero');
        const headline = within(form()).getByLabelText('Headline');
        await userEvent.clear(headline);
        await userEvent.type(headline, 'Get strong');

        expect(within(screen.getByRole('region', { name: 'Live preview' })).getByRole('heading', { name: 'Get strong', level: 1 })).toBeInTheDocument();
    });

    it('FR-S4 reports "Draft saved" even when the server returns the SEO fields in another order', async () => {
        server.use(
            http.put(url('/provider/storefront'), async ({ request }) => {
                const body = (await request.json()) as { seo: { title?: string; og_image_url?: string }; blocks: any[] }; // eslint-disable-line @typescript-eslint/no-explicit-any
                return HttpResponse.json({
                    data: draft(
                        body.blocks.map((b) => ({ ...b, id: b.id })),
                        { seo: { title: body.seo.title, og_image_url: body.seo.og_image_url } },
                    ),
                });
            }),
        );
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });

        await edit('Search and sharing');
        await userEvent.type(screen.getByLabelText('Share image'), 'https://cdn.test/share.png');
        await userEvent.clear(screen.getByLabelText('Page title'));
        await userEvent.type(screen.getByLabelText('Page title'), 'Acme gym');
        await userEvent.click(screen.getByRole('button', { name: 'Save draft' }));

        expect(await screen.findByText('Draft saved')).toBeInTheDocument();
    });

    it('moves blocks up and down', async () => {
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });

        expect(within(card(/Hero block/)).getByRole('button', { name: 'Move up' })).toBeDisabled();
        await userEvent.click(within(card(/Hero block/)).getByRole('button', { name: 'Move down' }));

        expect(types()).toEqual(['About block', 'Hero block']);
    });

    it('hides a block from the page without deleting it, and shows it again', async () => {
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });
        const preview = screen.getByRole('region', { name: 'Live preview' });
        expect(within(preview).getByRole('heading', { name: 'Train smarter' })).toBeInTheDocument();

        await userEvent.click(within(card(/Hero block/)).getByRole('button', { name: 'Hide' }));
        expect(within(preview).queryByRole('heading', { name: 'Train smarter' })).not.toBeInTheDocument();
        expect(types()).toEqual(['Hero block', 'About block']);

        await userEvent.click(within(card(/Hero block/)).getByRole('button', { name: 'Show' }));
        expect(within(preview).getByRole('heading', { name: 'Train smarter' })).toBeInTheDocument();
    });

    it('removes a block', async () => {
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });

        await userEvent.click(within(card(/About block/)).getByRole('button', { name: 'Remove' }));

        expect(types()).toEqual(['Hero block']);
    });

    it('adds and removes items in a list block', async () => {
        current = draft([{ id: 'f1', type: 'feature_list', visible: true, data: { heading: 'Why us', items: [{ title: 'Coaches', description: '' }] } }]);
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Feature list block/ });
        await edit('Feature list');
        await userEvent.click(within(form()).getByRole('button', { name: 'Add item' }));

        expect(within(form()).getAllByLabelText('Title')).toHaveLength(2);
        await userEvent.click(within(form()).getAllByRole('button', { name: 'Remove item' })[1]);
        expect(within(form()).getAllByLabelText('Title')).toHaveLength(1);
    });
});

describe('FR-S3 preview mode', () => {
    it('switches to a full-width preview with a device toggle and back to the editor', async () => {
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });
        const views = screen.getByRole('group', { name: 'View' });
        expect(within(views).getByRole('button', { name: 'Editor' })).toHaveAttribute('aria-pressed', 'true');

        await userEvent.click(within(views).getByRole('button', { name: 'Preview' }));

        expect(within(views).getByRole('button', { name: 'Preview' })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.queryByRole('list', { name: 'Sections' })).not.toBeInTheDocument();
        expect(within(screen.getByRole('region', { name: 'Live preview' })).getByRole('heading', { name: 'Train smarter' })).toBeInTheDocument();
        expect(screen.getByTestId('preview-frame')).toHaveAttribute('data-device', 'desktop');

        await userEvent.click(screen.getByRole('button', { name: 'Mobile' }));
        expect(screen.getByTestId('preview-frame')).toHaveAttribute('data-device', 'mobile');

        await userEvent.click(within(views).getByRole('button', { name: 'Editor' }));
        expect(types()).toEqual(['Hero block', 'About block']);
    });

    it('keeps the open form and unsaved edits when switching views', async () => {
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });
        await edit('Hero');
        await userEvent.clear(within(form()).getByLabelText('Headline'));
        await userEvent.type(within(form()).getByLabelText('Headline'), 'Lift');

        await userEvent.click(screen.getByRole('button', { name: 'Preview' }));
        expect(within(screen.getByRole('region', { name: 'Live preview' })).getByRole('heading', { name: 'Lift' })).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Editor' }));

        expect(within(form()).getByLabelText('Headline')).toHaveValue('Lift');
        expect(screen.getByText('Unsaved changes')).toBeInTheDocument();
    });
});

describe('FR-S3 saving and publishing', () => {
    it('keeps Save disabled until something changes, then saves the ordered draft with block ids', async () => {
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });
        expect(screen.getByRole('button', { name: 'Save draft' })).toBeDisabled();

        await userEvent.click(within(card(/Hero block/)).getByRole('button', { name: 'Move down' }));
        expect(screen.getByText('Unsaved changes')).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Save draft' }));

        await waitFor(() => expect(calls).toHaveLength(1));
        expect(calls[0].body.blocks.map((b: { id: string; type: string }) => [b.id, b.type])).toEqual([
            ['b2', 'about'],
            ['b1', 'hero'],
        ]);
        expect(calls[0].body.blocks[0]).toMatchObject({ visible: true, data: { heading: 'About us' } });
        expect(await screen.findByText('Draft saved')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save draft' })).toBeDisabled();
    });

    it('sends new blocks without an id', async () => {
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });

        await addSection(/^Text/);
        await userEvent.click(screen.getByRole('button', { name: 'Save draft' }));

        await waitFor(() => expect(calls).toHaveLength(1));
        expect(calls[0].body.blocks[2].id).toBeUndefined();
        expect(calls[0].body.blocks[2].type).toBe('text');
    });

    it('shows validation errors next to the field that caused them', async () => {
        server.use(
            http.put(url('/provider/storefront'), () =>
                HttpResponse.json({ message: 'invalid', errors: { 'blocks.0.data.headline': ['The headline field is required.'] } }, { status: 422 }),
            ),
        );
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });

        await edit('Hero');
        await userEvent.clear(within(form()).getByLabelText('Headline'));
        await userEvent.click(screen.getByRole('button', { name: 'Save draft' }));

        await waitFor(() => expect(within(form()).getByLabelText('Headline')).toHaveAccessibleDescription('The headline field is required.'));
    });

    it('opens the section with the error after a failed save and marks it in the list', async () => {
        server.use(
            http.put(url('/provider/storefront'), () =>
                HttpResponse.json({ message: 'invalid', errors: { 'blocks.1.data.heading': ['The heading field is required.'] } }, { status: 422 }),
            ),
        );
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });
        await userEvent.click(within(card(/Hero block/)).getByRole('button', { name: 'Hide' }));

        await userEvent.click(screen.getByRole('button', { name: 'Save draft' }));

        await waitFor(() => expect(within(form()).getByLabelText('Heading')).toHaveAccessibleDescription('The heading field is required.'));
        await userEvent.click(screen.getByRole('button', { name: 'Back to sections' }));
        expect(within(card(/About block/)).getByText('Needs attention')).toBeInTheDocument();
    });

    it('opens search and sharing when the error is in its fields', async () => {
        server.use(
            http.put(url('/provider/storefront'), () =>
                HttpResponse.json({ message: 'invalid', errors: { 'seo.title': ['The title is too long.'] } }, { status: 422 }),
            ),
        );
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });
        await userEvent.click(within(card(/Hero block/)).getByRole('button', { name: 'Hide' }));

        await userEvent.click(screen.getByRole('button', { name: 'Save draft' }));

        await waitFor(() => expect(within(form()).getByLabelText('Page title')).toHaveAccessibleDescription('The title is too long.'));
    });

    it('saves SEO fields with the draft', async () => {
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });

        await edit('Search and sharing');
        await userEvent.type(screen.getByLabelText('Meta description'), 'Best gym in town');
        await userEvent.click(screen.getByRole('button', { name: 'Save draft' }));

        await waitFor(() => expect(calls[0].body.seo).toMatchObject({ title: 'Acme', description: 'Best gym in town' }));
    });

    it('publishes after saving, in that order', async () => {
        const order: string[] = [];
        server.use(
            http.put(url('/provider/storefront'), async () => (order.push('save'), HttpResponse.json({ data: current }))),
            http.post(
                url('/provider/storefront/publish'),
                () => (order.push('publish'), HttpResponse.json({ data: draft([hero, about], { published_at: '2026-10-24T10:00:00Z' }) })),
            ),
        );
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });

        await userEvent.click(within(card(/Hero block/)).getByRole('button', { name: 'Move down' }));
        await userEvent.click(screen.getByRole('button', { name: 'Publish' }));

        await waitFor(() => expect(order).toEqual(['save', 'publish']));
        expect(await screen.findByText(/Published/)).toBeInTheDocument();
    });

    it('says why publishing was refused', async () => {
        server.use(
            http.post(url('/provider/storefront/publish'), () =>
                HttpResponse.json({ message: 'Add at least one visible block before publishing.', code: 'storefront_empty' }, { status: 409 }),
            ),
        );
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });

        await userEvent.click(screen.getByRole('button', { name: 'Publish' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Add at least one visible block');
    });

    it('flags published changes that are not live yet, and unpublishes', async () => {
        let unpublished = false;
        current = draft([hero], { published_at: '2026-10-20T10:00:00Z', has_unpublished_changes: true });
        server.use(http.post(url('/provider/storefront/unpublish'), () => ((unpublished = true), HttpResponse.json({ data: draft([hero]) }))));
        renderWithProviders(<StorefrontEditorPage />);

        expect(await screen.findByText(/changes are not published/i)).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Unpublish' }));

        await waitFor(() => expect(unpublished).toBe(true));
    });

    it('offers no Unpublish while nothing is published', async () => {
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });

        expect(screen.queryByRole('button', { name: 'Unpublish' })).not.toBeInTheDocument();
    });

    it('opens a preview link on the real storefront host', async () => {
        server.use(
            http.post(url('/provider/storefront/preview'), () =>
                HttpResponse.json({ data: { url: 'http://acme.platform.test/?preview=tok', token: 'tok', expires_at: '2026-10-24T11:00:00Z' } }),
            ),
        );
        renderWithProviders(<StorefrontEditorPage />);
        await screen.findByRole('listitem', { name: /Hero block/ });

        await userEvent.click(screen.getByRole('button', { name: 'Preview in new tab' }));

        await waitFor(() => expect(nav.openInNewTab).toHaveBeenCalledWith('http://acme.platform.test/?preview=tok'));
    });
});
