import { screen, waitFor, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { StorefrontApp } from './StorefrontApp';

const url = (p: string) => `http://localhost/api/v1${p}`;

const provider = {
    name: 'Acme Gym',
    slug: 'acme',
    support_email: 'help@acme.test',
    currency: 'usd',
    branding: { primary_color: null as string | null },
    legal: { terms_url: 'https://acme.test/terms', privacy_url: 'https://acme.test/privacy', refund_url: null },
};
const blocks = [
    {
        id: 'b1',
        type: 'hero',
        data: { headline: 'Train smarter', subheadline: 'Plans for everyone', button_label: 'Join now', button_url: 'https://acme.test/join' },
    },
    { id: 'b2', type: 'about', data: { heading: 'About us', body: '<p>We are a <strong>gym</strong>.</p>' } },
    {
        id: 'b3',
        type: 'feature_list',
        data: {
            heading: 'Why us',
            items: [
                { title: 'Coaches', description: 'Certified' },
                { title: 'Hours', description: 'Open late' },
            ],
        },
    },
    { id: 'b4', type: 'testimonials', data: { items: [{ quote: 'Life changing', author: 'Sam', role: 'Member' }] } },
    { id: 'b5', type: 'faq', data: { items: [{ question: 'Can I cancel?', answer: '<p>Any time.</p>' }] } },
    { id: 'b6', type: 'plans', data: { heading: 'Pricing' } },
    { id: 'b7', type: 'cta', data: { heading: 'Ready?', button_label: 'Start today', button_url: 'https://acme.test/start' } },
    { id: 'b8', type: 'contact', data: { heading: 'Say hello', email: 'hello@acme.test' } },
];
const plans = [
    {
        id: 'p1',
        name: 'Starter',
        description: 'To try us',
        features: ['One class a week'],
        trial_days: 7,
        prices: [{ id: 'pr1', amount: 1900, currency: 'usd', interval: 'month', interval_count: 1 }],
    },
];
const published = {
    provider,
    available: true,
    published: true,
    preview: false,
    page: { seo: { title: 'Acme Gym | Train', description: 'Best gym in town', og_image_url: 'https://cdn.test/og.png' }, blocks },
    plans,
};

let lastRequest: URL | null = null;
const server = setupServer();
const respond = (body: unknown, status = 200) =>
    server.use(
        http.get(url('/public/storefront'), ({ request }) => {
            lastRequest = new URL(request.url);
            return HttpResponse.json(status === 200 ? { data: body } : (body as object), { status });
        }),
    );

beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    lastRequest = null;
    window.history.pushState({}, '', '/');
    document.title = '';
    document.head.querySelectorAll('meta[data-storefront]').forEach((m) => m.remove());
});
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe('FR-S1 storefront', () => {
    it("renders the published blocks in order under the provider's name", async () => {
        respond(published);
        renderWithProviders(<StorefrontApp />);

        expect(await screen.findByRole('heading', { name: 'Train smarter', level: 1 })).toBeInTheDocument();
        expect(screen.getAllByText('Acme Gym').length).toBeGreaterThan(0);
        const headings = screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent);
        expect(headings).toEqual(['About us', 'Why us', 'Pricing', 'Ready?', 'Say hello']);
        expect(screen.getByText('Certified')).toBeInTheDocument();
        expect(screen.getByText('Life changing')).toBeInTheDocument();
        expect(screen.getByText('Can I cancel?')).toBeInTheDocument();
        expect(screen.getByText('Any time.')).toBeInTheDocument();
    });

    it('shows live plans with price, trial and features', async () => {
        respond(published);
        renderWithProviders(<StorefrontApp />);

        const plan = await screen.findByRole('article', { name: 'Starter' });
        expect(within(plan).getByText('$19.00 / month')).toBeInTheDocument();
        expect(within(plan).getByText('7-day free trial')).toBeInTheDocument();
        expect(within(plan).getByText('One class a week')).toBeInTheDocument();
    });

    it('says plans are coming when there are none', async () => {
        respond({ ...published, plans: [] });
        renderWithProviders(<StorefrontApp />);

        expect(await screen.findByText(/plans are coming soon/i)).toBeInTheDocument();
    });

    it("links buttons to the provider's URLs, opened safely", async () => {
        respond(published);
        renderWithProviders(<StorefrontApp />);

        const join = await screen.findByRole('link', { name: 'Join now' });
        expect(join).toHaveAttribute('href', 'https://acme.test/join');
        expect(join).toHaveAttribute('rel', expect.stringContaining('noopener'));
        expect(screen.getByRole('link', { name: 'hello@acme.test' })).toHaveAttribute('href', 'mailto:hello@acme.test');
    });

    it('shows the legal links and support email in the footer', async () => {
        respond(published);
        renderWithProviders(<StorefrontApp />);

        const footer = await screen.findByRole('contentinfo');
        expect(within(footer).getByRole('link', { name: 'Terms of service' })).toHaveAttribute('href', 'https://acme.test/terms');
        expect(within(footer).getByRole('link', { name: 'Privacy policy' })).toBeInTheDocument();
        expect(within(footer).queryByRole('link', { name: 'Refund policy' })).not.toBeInTheDocument();
        expect(within(footer).getByRole('link', { name: 'help@acme.test' })).toBeInTheDocument();
    });
});

describe('FR-S4 SEO', () => {
    it('sets the title, description and social tags from the page SEO', async () => {
        respond(published);
        renderWithProviders(<StorefrontApp />);
        await screen.findByRole('heading', { name: 'Train smarter' });

        await waitFor(() => expect(document.title).toBe('Acme Gym | Train'));
        expect(document.head.querySelector('meta[name="description"]')).toHaveAttribute('content', 'Best gym in town');
        expect(document.head.querySelector('meta[property="og:title"]')).toHaveAttribute('content', 'Acme Gym | Train');
        expect(document.head.querySelector('meta[property="og:image"]')).toHaveAttribute('content', 'https://cdn.test/og.png');
    });

    it('falls back to the provider name when no title is set', async () => {
        respond({ ...published, page: { seo: {}, blocks } });
        renderWithProviders(<StorefrontApp />);

        await waitFor(() => expect(document.title).toBe('Acme Gym'));
    });
});

describe('FR-P7 branding', () => {
    it("applies the provider's brand colour to the storefront", async () => {
        respond({ ...published, provider: { ...provider, branding: { primary_color: '#e11d48' } } });
        const { container } = renderWithProviders(<StorefrontApp />);
        await screen.findByRole('heading', { name: 'Train smarter' });

        const root = container.querySelector('[data-storefront-root]') as HTMLElement;
        expect(root.style.getPropertyValue('--primary')).toBe('#e11d48');
        expect(root.style.getPropertyValue('--primary-foreground')).toBe('#ffffff');
    });

    it('picks dark text on a light brand colour', async () => {
        respond({ ...published, provider: { ...provider, branding: { primary_color: '#fde047' } } });
        const { container } = renderWithProviders(<StorefrontApp />);
        await screen.findByRole('heading', { name: 'Train smarter' });

        expect((container.querySelector('[data-storefront-root]') as HTMLElement).style.getPropertyValue('--primary-foreground')).not.toBe('#ffffff');
    });
});

describe('FR-S9 and FR-S3 states', () => {
    it('shows an unavailable page, and none of the content, when the provider is paused', async () => {
        respond({ provider, available: false, published: true, preview: false, page: null, plans: [] });
        renderWithProviders(<StorefrontApp />);

        expect(await screen.findByRole('heading', { name: /unavailable/i })).toBeInTheDocument();
        expect(screen.queryByText('Train smarter')).not.toBeInTheDocument();
    });

    it('shows a coming-soon page while nothing is published', async () => {
        respond({ provider, available: true, published: false, preview: false, page: null, plans: [] });
        renderWithProviders(<StorefrontApp />);

        expect(await screen.findByRole('heading', { name: /coming soon/i })).toBeInTheDocument();
    });

    it('shows a not-found page for an address nobody owns', async () => {
        respond({ message: 'No provider lives at this address.', code: 'tenant_not_found' }, 404);
        renderWithProviders(<StorefrontApp />);

        expect(await screen.findByRole('heading', { name: /couldn.t find/i })).toBeInTheDocument();
    });

    it('shows a retry when the API fails', async () => {
        respond({ message: 'Server error.' }, 500);
        renderWithProviders(<StorefrontApp />);

        expect(await screen.findByRole('alert')).toHaveTextContent('Server error.');
        expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
    });

    it('passes the preview token through and tells the viewer this is a preview', async () => {
        window.history.pushState({}, '', '/?preview=abc.def');
        respond({ ...published, published: false, preview: true });
        renderWithProviders(<StorefrontApp />);

        expect(await screen.findByRole('heading', { name: 'Train smarter' })).toBeInTheDocument();
        expect(lastRequest!.searchParams.get('preview')).toBe('abc.def');
        expect(screen.getByRole('status')).toHaveTextContent(/preview/i);
    });

    it('does not claim to be a preview when the token was rejected', async () => {
        window.history.pushState({}, '', '/?preview=expired');
        respond(published);
        renderWithProviders(<StorefrontApp />);
        await screen.findByRole('heading', { name: 'Train smarter' });

        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });
});

describe('FR-S7 defence in depth', () => {
    it('neutralises scripts and handlers even if the API ever returned them', async () => {
        const dirty = '<p onclick="steal()">Hi</p><script>window.__pwned = true</script><img src=x onerror="window.__pwned = true">';
        respond({ ...published, page: { seo: {}, blocks: [{ id: 'x', type: 'text', data: { body: dirty } }] } });
        const { container } = renderWithProviders(<StorefrontApp />);

        await screen.findByText('Hi');

        expect(container.querySelector('script')).toBeNull();
        expect(container.querySelector('img')).toBeNull();
        expect(container.querySelector('[onclick]')).toBeNull();
        expect((window as unknown as { __pwned?: boolean }).__pwned).toBeUndefined();
    });
});
