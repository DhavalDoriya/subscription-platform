<?php

namespace App\Http\Middleware;

use App\Services\Providers\CurrentProvider;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provider-area requests act as the signed-in user's provider, so the tenant scope applies to every query
 * even if a controller forgets a where(). Note: route-model binding runs before this, so look rows up inside the controller.
 */
class SetProviderTenant
{
    public function __construct(private TenantContext $tenancy, private CurrentProvider $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenancy->set($this->current->for($request->user('api')));

        try {
            return $next($request);
        } finally {
            $this->tenancy->forget();
        }
    }
}
