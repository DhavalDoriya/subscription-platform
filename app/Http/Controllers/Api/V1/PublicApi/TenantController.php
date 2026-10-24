<?php

namespace App\Http\Controllers\Api\V1\PublicApi;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class TenantController extends Controller
{
    /** Who is this host? null on the main app, the public face of the provider on a storefront subdomain. */
    public function show(TenantContext $tenancy): JsonResponse
    {
        $provider = $tenancy->current();

        return ApiResponse::success($provider ? [
            'name' => $provider->name,
            'slug' => $provider->slug,
            'available' => $provider->status->isOperational(),
        ] : null);
    }
}
