import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { useState } from 'react';
import { renderWithProviders } from '@/test/render';
import { ImageField } from './ImageField';

const url = (p: string) => `http://localhost/api/v1${p}`;
const media = (id: string, name: string) => ({
    id,
    url: `https://cdn.test/${name}`,
    original_name: name,
    mime_type: 'image/png',
    size: 1000,
    width: 800,
    height: 600,
    alt: null,
    created_at: '2026-10-01T00:00:00Z',
});
const meta = (total: number) => ({ current_page: 1, per_page: 12, total, last_page: 1 });

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function Harness({ initial = '', error }: { initial?: string; error?: string }) {
    const [value, setValue] = useState(initial);
    return <ImageField label="Image" value={value} error={error} onChange={setValue} />;
}

it('FR-S5 uploading a file fills the field with the new url', async () => {
    let contentType: string | null = null;
    server.use(
        http.post(url('/provider/media'), ({ request }) => {
            contentType = request.headers.get('content-type'); // jsdom's File cannot be parsed back by undici, so check the encoding only
            return HttpResponse.json({ data: media('m1', 'logo.png') }, { status: 201 });
        }),
    );
    renderWithProviders(<Harness />);

    await userEvent.upload(screen.getByLabelText('Image file'), new File(['x'], 'logo.png', { type: 'image/png' }));

    await waitFor(() => expect(screen.getByLabelText('Image')).toHaveValue('https://cdn.test/logo.png'));
    expect(contentType).toContain('multipart/form-data');
});

it('FR-S5 shows the server message when the upload is rejected', async () => {
    server.use(
        http.post(url('/provider/media'), () =>
            HttpResponse.json({ message: 'The file field must not be greater than 5120 kilobytes.', errors: { file: ['Too big.'] } }, { status: 422 }),
        ),
    );
    renderWithProviders(<Harness />);

    await userEvent.upload(screen.getByLabelText('Image file'), new File(['x'], 'big.png', { type: 'image/png' }));

    expect(await screen.findByText('Too big.')).toBeInTheDocument();
    expect(screen.getByLabelText('Image')).toHaveValue('');
});

it('FR-S5 picks an existing image from the library and filters it by name', async () => {
    const searches: string[] = [];
    server.use(
        http.get(url('/provider/media'), ({ request }) => {
            const search = new URL(request.url).searchParams.get('search') ?? '';
            searches.push(search);
            const all = [media('m1', 'team.png'), media('m2', 'banner.png')];
            const rows = all.filter((m) => m.original_name.includes(search));
            return HttpResponse.json({ data: rows, meta: meta(rows.length) });
        }),
    );
    renderWithProviders(<Harness />);

    await userEvent.click(screen.getByRole('button', { name: 'Choose from library' }));
    const dialog = await screen.findByRole('dialog');
    expect(await within(dialog).findByRole('button', { name: 'team.png' })).toBeInTheDocument();

    await userEvent.type(within(dialog).getByRole('searchbox'), 'banner');
    await waitFor(() => expect(within(dialog).queryByRole('button', { name: 'team.png' })).not.toBeInTheDocument());

    await userEvent.click(within(dialog).getByRole('button', { name: 'banner.png' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(screen.getByLabelText('Image')).toHaveValue('https://cdn.test/banner.png');
});

it('FR-S5 previews the current image, can clear it and keeps typed urls working', async () => {
    renderWithProviders(<Harness initial="https://cdn.test/a.png" />);

    expect(screen.getByRole('img', { name: 'Image preview' })).toHaveAttribute('src', 'https://cdn.test/a.png');

    await userEvent.click(screen.getByRole('button', { name: 'Remove image' }));
    expect(screen.getByLabelText('Image')).toHaveValue('');
    expect(screen.queryByRole('img', { name: 'Image preview' })).not.toBeInTheDocument();

    await userEvent.type(screen.getByLabelText('Image'), 'https://other.test/b.png');
    expect(screen.getByLabelText('Image')).toHaveValue('https://other.test/b.png');
});

it('shows a validation error passed by the form', () => {
    renderWithProviders(<Harness error="Enter a valid URL." />);
    expect(screen.getByText('Enter a valid URL.')).toBeInTheDocument();
});
