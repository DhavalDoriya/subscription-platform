<?php

namespace App\Http\Controllers\Api\V1\PublicApi;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Storefront\StorefrontService;
use App\Support\Api\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontController extends Controller
{
    /** Everything the storefront SPA needs for the host it was loaded from. Served only from a provider subdomain. */
    public function show(Request $request, TenantContext $tenancy, StorefrontService $storefront): JsonResponse
    {
        $provider = $tenancy->current() ?? throw new ApiException(__('api.providers.tenant_not_found'), 404, 'tenant_not_found');

        return ApiResponse::success($storefront->publicView($provider, $request->query('preview')));
    }
}
