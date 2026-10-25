<?php

use App\Exceptions\ApiException;
use Illuminate\Contracts\Debug\ExceptionHandler;

it('FR-A6 sends the basic hardening headers on the app and the API', function (string $path) {
    $response = $this->get($path);

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()');
})->with(['/', '/api/v1/public/config']);

it('FR-A6 serves the SPA with a content security policy that allows only ourselves and Stripe', function () {
    config(['security.csp' => true]);

    $csp = $this->get('/')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'self'")
        ->and($csp)->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]{24}' https:\/\/js\.stripe\.com/")
        ->and($csp)->toContain('frame-src https://js.stripe.com https://hooks.stripe.com')
        ->and($csp)->toContain('connect-src')->toContain('https://api.stripe.com')
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->not->toContain('unsafe-eval');
});

it('FR-A6 leaves the CSP off when it is switched off (local development with Vite)', function () {
    config(['security.csp' => false]);

    expect($this->get('/')->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('FR-A6 only sends HSTS over https in production', function () {
    expect($this->get('/')->headers->has('Strict-Transport-Security'))->toBeFalse();

    config(['security.hsts' => true]);

    expect($this->get('https://platform.test/')->headers->get('Strict-Transport-Security'))->toContain('max-age=')->toContain('includeSubDomains');
});

it('FR-A6 does not reveal the framework or server in headers', function () {
    $headers = $this->get('/')->headers;

    expect($headers->has('X-Powered-By'))->toBeFalse();
});

it('FR-A6 the inline config script carries the nonce of the policy, and the nonce changes per request', function () {
    config(['security.csp' => true]);

    $first = $this->get('/');
    preg_match("/'nonce-([^']+)'/", $first->headers->get('Content-Security-Policy'), $match);
    $second = $this->get('/');
    preg_match("/'nonce-([^']+)'/", $second->headers->get('Content-Security-Policy'), $other);

    expect($first->getContent())->toContain('<script nonce="'.$match[1].'">window.__APP__')
        ->and($match[1])->not->toBe($other[1]);
});

it('FR-A6 rate limits the unauthenticated public endpoints per IP', function () {
    config(['auth.rate_limits.public' => 5]);

    foreach (range(1, 5) as $i) {
        $this->getJson('/api/v1/public/config')->assertOk();
    }

    $this->getJson('/api/v1/public/config')->assertStatus(429);
});

it('FR-A6 does not open the API to other origins (the SPA is served from the same host)', function () {
    $response = $this->call('OPTIONS', '/api/v1/public/config', server: ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET']);
    $get = $this->withHeader('Origin', 'https://evil.example')->getJson('/api/v1/public/config');

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull()
        ->and($get->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

it('FR-A6 lets images load from the media disk origin, such as a CDN', function () {
    config(['security.csp' => true, 'filesystems.disks.public.url' => 'https://cdn.example.com/storage']);

    expect($this->get('/')->headers->get('Content-Security-Policy'))->toContain("img-src 'self' data: blob: https: https://cdn.example.com;");
});

it('FR-L3 the API docs are closed in production unless switched on', function () {
    $this->app['env'] = 'production';
    $this->get('/docs/api')->assertForbidden();

    config(['app.api_docs' => true]);
    $this->get('/docs/api')->assertOk();
});

it('FR-L3 error tracking stays off without a DSN and never sends personal data by default', function () {
    expect(config('sentry.dsn'))->toBeNull()
        ->and(config('sentry.send_default_pii'))->toBeFalse();
});

it('FR-L3 expected business errors are not reported, real server errors are', function () {
    $handler = app(ExceptionHandler::class);

    expect($handler->shouldReport(new ApiException('nope', 422, 'invalid')))->toBeFalse()
        ->and($handler->shouldReport(new ApiException('nope', 409, 'conflict')))->toBeFalse()
        ->and($handler->shouldReport(new ApiException('mail down', 502, 'mail_test_failed')))->toBeTrue()
        ->and($handler->shouldReport(new RuntimeException('boom')))->toBeTrue();
});
