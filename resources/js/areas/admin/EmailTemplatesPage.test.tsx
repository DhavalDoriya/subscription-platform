import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { Route, Routes } from 'react-router-dom';
import { renderWithProviders } from '@/test/render';
import { EmailTemplateEditPage } from './EmailTemplateEditPage';
import { EmailTemplatesPage } from './EmailTemplatesPage';

const url = (p: string) => `http://localhost/api/v1${p}`;
const base = {
    id: 't1',
    key: 'dunning.payment_failed',
    name: 'Payment failed',
    description: 'First notice after a renewal payment fails.',
    subject: 'Your payment to {{provider.name}} failed',
    enabled: true,
    version: 2,
    updated_at: '2026-11-02T10:00:00Z',
};
const detail = {
    ...base,
    html_body: '<p>Hi {{customer.name}}</p>',
    text_body: 'Hi {{customer.name}}',
    variables: { 'customer.name': 'Customer name', 'provider.name': 'Provider name' },
    samples: { 'customer.name': 'Ada', 'provider.name': 'Acme' },
};
const welcome = { ...base, id: 't2', key: 'auth.welcome', name: 'Welcome', enabled: false, version: 1 };
const requests: URL[] = [];

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
beforeEach(() => {
    requests.length = 0;
    server.use(
        http.get(url('/admin/email-templates'), ({ request }) => {
            requests.push(new URL(request.url));
            return HttpResponse.json({ data: [base, welcome], meta: { current_page: 1, per_page: 15, total: 2, last_page: 1 } });
        }),
    );
});
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe('FR-AD8 email templates list', () => {
    it('FR-E1 lists templates with their key, status and version, each linking to the editor', async () => {
        renderWithProviders(<EmailTemplatesPage />);

        const row = await screen.findByRole('row', { name: /dunning\.payment_failed/ });
        expect(within(row).getByText('Payment failed')).toBeInTheDocument();
        expect(within(row).getByText('Enabled')).toBeInTheDocument();
        expect(within(row).getByText('v2')).toBeInTheDocument();
        expect(within(row).getByRole('link', { name: /Edit/ })).toHaveAttribute('href', '/admin/email-templates/t1');
        expect(within(screen.getByRole('row', { name: /auth\.welcome/ })).getByText('Disabled')).toBeInTheDocument();
    });

    it('searches templates', async () => {
        renderWithProviders(<EmailTemplatesPage />);
        await screen.findByText('auth.welcome');

        await userEvent.type(screen.getByRole('searchbox', { name: 'Search templates' }), 'dunning');

        await waitFor(() => expect(requests.at(-1)!.searchParams.get('search')).toBe('dunning'));
    });
});

function renderEditor() {
    return renderWithProviders(
        <Routes>
            <Route path="/admin/email-templates/:id" element={<EmailTemplateEditPage />} />
        </Routes>,
        { route: '/admin/email-templates/t1' },
    );
}

describe('FR-AD8 email template editor', () => {
    beforeEach(() =>
        server.use(
            http.get(url('/admin/email-templates/t1'), () => HttpResponse.json({ data: detail })),
            http.get(url('/admin/email-templates/t1/versions'), () =>
                HttpResponse.json({
                    data: [
                        {
                            id: 'v2',
                            version: 2,
                            subject: 'New',
                            html_body: '<p>n</p>',
                            text_body: 'n',
                            author: 'Ada Admin',
                            created_at: '2026-11-02T10:00:00Z',
                        },
                        { id: 'v1', version: 1, subject: 'Old', html_body: '<p>o</p>', text_body: 'o', author: null, created_at: '2026-11-01T10:00:00Z' },
                    ],
                }),
            ),
            http.post(url('/admin/email-templates/t1/preview'), async ({ request }) => {
                const body = (await request.json()) as { subject?: string; html_body?: string };
                requests.push(new URL(request.url));
                return HttpResponse.json({
                    data: {
                        subject: (body.subject ?? '').replace('{{provider.name}}', 'Acme'),
                        html: (body.html_body ?? '').replace('{{customer.name}}', 'Ada'),
                        text: '',
                    },
                });
            }),
        ),
    );

    it('FR-E2 shows the template with its documented variables', async () => {
        renderEditor();

        expect(await screen.findByLabelText('Subject')).toHaveValue('Your payment to {{provider.name}} failed');
        expect(screen.getByLabelText('HTML body')).toHaveValue('<p>Hi {{customer.name}}</p>');
        expect(screen.getByLabelText('Text body')).toHaveValue('Hi {{customer.name}}');
        const variables = screen.getByRole('region', { name: 'Variables' });
        expect(within(variables).getByText('{{customer.name}}')).toBeInTheDocument();
        expect(within(variables).getByText('Provider name')).toBeInTheDocument();
    });

    it('FR-E2 previews the unsaved edit with sample data', async () => {
        renderEditor();
        const html = await screen.findByLabelText('HTML body');

        await userEvent.clear(html);
        await userEvent.type(html, 'Dear {{{{customer.name}}', { skipClick: true });

        const preview = screen.getByRole('region', { name: 'Preview' });
        await waitFor(() => expect(within(preview).getByTitle('Email preview')).toHaveAttribute('srcdoc', expect.stringContaining('Dear Ada')));
        expect(await within(preview).findByText('Your payment to Acme failed')).toBeInTheDocument();
    });

    it('FR-E1 saves the edit', async () => {
        let body: unknown;
        server.use(
            http.put(url('/admin/email-templates/t1'), async ({ request }) => {
                body = await request.json();
                return HttpResponse.json({ data: { ...detail, version: 3 } });
            }),
        );
        renderEditor();
        const subject = await screen.findByLabelText('Subject');

        await userEvent.clear(subject);
        await userEvent.type(subject, 'Oops');
        await userEvent.click(screen.getByLabelText('Enabled'));
        await userEvent.click(screen.getByRole('button', { name: 'Save template' }));

        await waitFor(() =>
            expect(body).toEqual({ subject: 'Oops', html_body: '<p>Hi {{customer.name}}</p>', text_body: 'Hi {{customer.name}}', enabled: false }),
        );
    });

    it('FR-E2 shows why an unknown placeholder was refused', async () => {
        server.use(
            http.put(url('/admin/email-templates/t1'), () =>
                HttpResponse.json(
                    { message: 'The given data was invalid.', errors: { html_body: ['These placeholders are not available in this template: bogus.'] } },
                    { status: 422 },
                ),
            ),
        );
        renderEditor();
        await screen.findByLabelText('Subject');

        await userEvent.click(screen.getByRole('button', { name: 'Save template' }));

        expect(await screen.findByText(/placeholders are not available/)).toBeInTheDocument();
    });

    it('FR-E1 lists the version history and restores an older version', async () => {
        let restored = false;
        server.use(
            http.post(url('/admin/email-templates/t1/versions/1/restore'), () => ((restored = true), HttpResponse.json({ data: { ...detail, version: 3 } }))),
        );
        renderEditor();

        const history = await screen.findByRole('region', { name: 'Version history' });
        expect(await within(history).findByText('Version 2')).toBeInTheDocument();
        expect(within(history).getByText(/Ada Admin/)).toBeInTheDocument();
        expect(within(history).queryByRole('button', { name: /Restore version 2/ })).not.toBeInTheDocument();

        await userEvent.click(within(history).getByRole('button', { name: 'Restore version 1' }));

        await waitFor(() => expect(restored).toBe(true));
    });

    it('FR-E2 sends a test email and reports the outcome', async () => {
        let body: unknown;
        server.use(
            http.post(url('/admin/email-templates/t1/test'), async ({ request }) => {
                body = await request.json();
                return HttpResponse.json({ data: { id: 'l1', status: 'failed', error: 'Connection refused', to_email: 'me@example.com' } });
            }),
        );
        renderEditor();
        await screen.findByLabelText('Subject');

        await userEvent.type(screen.getByLabelText('Send a test to'), 'me@example.com');
        await userEvent.click(screen.getByRole('button', { name: 'Send test' }));

        await waitFor(() => expect(body).toEqual({ to: 'me@example.com' }));
        expect(await screen.findByText(/Connection refused/)).toBeInTheDocument();
    });
});
