<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\SaveStorefrontRequest;
use App\Services\Storefront\StorefrontService;
use App\Support\Api\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontController extends Controller
{
    public function __construct(private StorefrontService $storefront, private TenantContext $tenancy) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->storefront->draft($this->tenancy->current()));
    }

    public function update(SaveStorefrontRequest $request): JsonResponse
    {
        return ApiResponse::success($this->storefront->save($this->tenancy->current(), $request->validated('seo') ?? [], $this->inOrder($request->validated('blocks'))));
    }

    /** validated() does not promise the order of nested arrays; the position the editor sent is the order. */
    private function inOrder(array $blocks): array
    {
        ksort($blocks);

        return array_values($blocks);
    }

    public function publish(Request $request): JsonResponse
    {
        return ApiResponse::success($this->storefront->publish($this->tenancy->current(), $request->user('api')));
    }

    public function unpublish(Request $request): JsonResponse
    {
        return ApiResponse::success($this->storefront->unpublish($this->tenancy->current(), $request->user('api')));
    }

    /** A link to the real storefront host showing the draft, valid for an hour. */
    public function preview(): JsonResponse
    {
        $provider = $this->tenancy->current();
        $token = $this->storefront->previewToken($provider);
        $app = parse_url((string) config('app.url'));
        $host = $provider->slug.'.'.config('app.base_domain').(isset($app['port']) ? ':'.$app['port'] : '');

        return ApiResponse::success([
            'url' => ($app['scheme'] ?? 'https')."://{$host}/?preview=".urlencode($token),
            'token' => $token,
            'expires_at' => date(DATE_ATOM, $this->storefront->previewExpiry($token)),
        ]);
    }
}
