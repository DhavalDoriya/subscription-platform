<?php

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** Every API route under a protected prefix, with its parameters filled in, as [method, uri, route name]. */
function protectedRoutes(string $prefix): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route) => Str::startsWith($route->uri(), "api/v1/{$prefix}/") || $route->uri() === "api/v1/{$prefix}")
        ->flatMap(fn (LaravelRoute $route) => collect($route->methods())->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS']))->map(
            fn ($method) => [$method, '/'.preg_replace(['/\{version\}/', '/\{[^}]+\}/'], ['1', '00000000-0000-0000-0000-000000000000'], $route->uri()), $route->getName() ?? $route->uri()],
        ))
        ->values()->all();
}

function call(string $method, string $uri)
{
    return test()->json($method, $uri);
}

it('FR-A6 the sweep finds the routes it is meant to guard', function () {
    expect(count(protectedRoutes('admin')))->toBeGreaterThan(40)
        ->and(count(protectedRoutes('provider')))->toBeGreaterThan(25)
        ->and(count(protectedRoutes('customer')))->toBeGreaterThan(5);
});

it('FR-A6 every admin, provider and customer route refuses a guest with 401', function (string $area) {
    $failures = [];
    foreach (protectedRoutes($area) as [$method, $uri]) {
        asGuest();
        $status = call($method, $uri)->getStatusCode();
        if ($status !== 401) {
            $failures[] = "{$method} {$uri} answered {$status}";
        }
    }

    expect($failures)->toBe([]);
})->with(['admin', 'provider', 'customer']);

it('FR-A6 every admin route refuses a customer and a provider owner with 403', function (string $who) {
    $user = $who === 'customer' ? customerSession() : providerOwnerSession()[0];
    $failures = [];

    foreach (protectedRoutes('admin') as [$method, $uri]) {
        $status = call($method, $uri)->getStatusCode();
        if ($status !== 403) {
            $failures[] = "{$method} {$uri} answered {$status}";
        }
    }

    expect($failures)->toBe([]);
})->with(['customer', 'provider owner']);

it('FR-A6 super-admin-only routes refuse an ordinary admin', function () {
    adminSession('admin');
    $failures = [];

    foreach (protectedRoutes('admin') as [$method, $uri, $name]) {
        if (! str_contains($uri, '/admin/admins')) {
            continue;
        }
        $status = call($method, $uri)->getStatusCode();
        if ($status !== 403) {
            $failures[] = "{$method} {$uri} answered {$status}";
        }
    }

    expect($failures)->toBe([]);
});

it('FR-A6 provider routes refuse a signed-in customer who owns no provider', function () {
    customerSession();
    $failures = [];

    foreach (protectedRoutes('provider') as [$method, $uri]) {
        if (str_ends_with($uri, '/provider/register')) {
            continue; // any signed-in user may register a provider
        }
        $status = call($method, $uri)->getStatusCode();
        if (! in_array($status, [403, 404], true)) {
            $failures[] = "{$method} {$uri} answered {$status}";
        }
    }

    expect($failures)->toBe([]);
});
