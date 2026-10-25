<?php

use App\Exceptions\ApiException;
use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsureNotBlocked;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetProviderTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Sentry\Laravel\Integration;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->append(SetLocale::class);
        $middleware->appendToGroup('api', EnsureNotBlocked::class);

        // Authorise before route-model binding runs, so a user who may not see an area gets 403 whether or not the id exists.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: RoleMiddleware::class);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'provider.tenant' => SetProviderTenant::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Error tracking (Sentry reads SENTRY_LARAVEL_DSN; with none set it does nothing).
        Integration::handles($exceptions);

        // A refused refund or a validation message is the API working, not a bug: only server-side failures are reported.
        $exceptions->dontReportWhen(fn (Throwable $e) => $e instanceof ApiException && $e->status < 500);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // One error envelope for the whole API (see App\Support\Api\ApiResponse).
        $exceptions->render(
            fn (Throwable $e, Request $request) => $request->is('api/*') ? ApiExceptionRenderer::render($e) : null,
        );
    })->create();
