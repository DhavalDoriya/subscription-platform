<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Provider;
use App\Support\Tenancy\SlugRules;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from `<slug>.<base domain>`. The bare domain, reserved subdomains and foreign hosts
 * are the main app (no tenant). An unknown slug is a 404, so a typo never falls through to platform scope.
 */
class ResolveTenant
{
    public function __construct(private TenantContext $tenancy, private SlugRules $slugs) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->slugFrom($request->getHost());

        if ($slug !== null) {
            $provider = Provider::where('slug', $slug)->first()
                ?? throw new ApiException(__('api.providers.tenant_not_found'), 404, 'tenant_not_found');

            $this->tenancy->set($provider);
        }

        try {
            return $next($request);
        } finally {
            $this->tenancy->forget();
        }
    }

    private function slugFrom(string $host): ?string
    {
        $suffix = '.'.config('app.base_domain');

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $label = substr($host, 0, -strlen($suffix));

        return $label === '' || str_contains($label, '.') || $this->slugs->isReserved($label) ? null : $label;
    }
}
