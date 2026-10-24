import { http as mock, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { api, ApiError, setAccessTokenGetter } from '.';

const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
afterEach(() => {
    server.resetHandlers();
    setAccessTokenGetter(() => null);
});
afterAll(() => server.close());

const failure = (request: Promise<unknown>) =>
    request.then(
        () => {
            throw new Error('expected request to fail');
        },
        (e: ApiError) => e,
    );

const url = (path: string) => `http://localhost/api/v1${path}`;

describe('api client', () => {
    it('unwraps single items to data', async () => {
        server.use(mock.get(url('/health'), () => HttpResponse.json({ data: { status: 'ok' } })));
        await expect(api.get<{ status: string }>('/health')).resolves.toEqual({ status: 'ok' });
    });

    it('returns data and meta for lists and sends list params', async () => {
        let query = '';
        server.use(
            mock.get(url('/admin/providers'), ({ request }) => {
                query = new URL(request.url).search;
                return HttpResponse.json({
                    data: [{ id: 'a' }],
                    meta: { current_page: 2, per_page: 10, total: 11, last_page: 2 },
                });
            }),
        );

        const page = await api.list<{ id: string }>('/admin/providers', { page: 2, per_page: 10, search: 'acme', status: 'active' });

        expect(page.data).toEqual([{ id: 'a' }]);
        expect(page.meta.total).toBe(11);
        expect(query).toContain('page=2');
        expect(query).toContain('search=acme');
        expect(query).toContain('status=active');
    });

    it('sends the bearer token when one is registered', async () => {
        let auth: string | null = null;
        server.use(
            mock.get(url('/me'), ({ request }) => {
                auth = request.headers.get('authorization');
                return HttpResponse.json({ data: {} });
            }),
        );
        setAccessTokenGetter(() => 'abc');
        await api.get('/me');
        expect(auth).toBe('Bearer abc');
    });

    it('turns validation errors into ApiError with field errors', async () => {
        server.use(
            mock.post(url('/providers'), () =>
                HttpResponse.json({ message: 'The slug is taken.', errors: { slug: ['Already in use.'] } }, { status: 422 }),
            ),
        );

        const error = await failure(api.post('/providers', {}));

        expect(error).toBeInstanceOf(ApiError);
        expect(error.isValidation).toBe(true);
        expect(error.firstError('slug')).toBe('Already in use.');
    });

    it('exposes status and code for other failures', async () => {
        server.use(mock.get(url('/x'), () => HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 })));
        const error = await failure(api.get('/x'));
        expect(error.isUnauthenticated).toBe(true);
        expect(error.message).toBe('Unauthenticated.');
    });

    it('maps network failures to ApiError status 0', async () => {
        server.use(mock.get(url('/down'), () => HttpResponse.error()));
        const error = await failure(api.get('/down'));
        expect(error).toBeInstanceOf(ApiError);
        expect(error.status).toBe(0);
    });

    it('resolves delete without a body', async () => {
        server.use(mock.delete(url('/things/1'), () => new HttpResponse(null, { status: 204 })));
        await expect(api.delete('/things/1')).resolves.toBeUndefined();
    });
});
