<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ProviderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListProvidersRequest;
use App\Http\Requests\Admin\RejectProviderRequest;
use App\Http\Requests\Admin\UpdateProviderRequest;
use App\Http\Resources\Admin\AdminProviderResource;
use App\Models\Provider;
use App\Services\Providers\ProviderLifecycle;
use App\Services\Storefront\StorefrontService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderController extends Controller
{
    public function __construct(private ProviderLifecycle $lifecycle) {}

    public function index(ListProvidersRequest $request): JsonResponse
    {
        $providers = Provider::query()->with('members.user')->withCount('customers')
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->search(), function ($query, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $query->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(slug) like ?', [$like]));
            })
            ->orderBy($request->sortColumn(ListProvidersRequest::SORTABLE, 'created_at'), $request->sortDirection())
            ->paginate($request->perPage());

        return ApiResponse::paginated($providers, AdminProviderResource::class);
    }

    public function show(Provider $provider): JsonResponse
    {
        return ApiResponse::success(new AdminProviderResource($provider));
    }

    public function update(UpdateProviderRequest $request, Provider $provider): JsonResponse
    {
        $provider->fill($request->validated());
        $changes = $provider->getDirty();

        if ($changes) {
            $before = array_intersect_key($provider->getOriginal(), $changes);
            $provider->save();
            activity()->causedBy($request->user('api'))->performedOn($provider)
                ->withProperties(['from' => $before, 'to' => $changes])->log('provider.updated');
        }

        return ApiResponse::success(new AdminProviderResource($provider));
    }

    public function approve(Request $request, Provider $provider): JsonResponse
    {
        return $this->move($request, $provider, ProviderStatus::Approved);
    }

    public function reject(RejectProviderRequest $request, Provider $provider): JsonResponse
    {
        return $this->move($request, $provider, ProviderStatus::Rejected, $request->validated('reason'));
    }

    public function activate(Request $request, Provider $provider): JsonResponse
    {
        return $this->move($request, $provider, ProviderStatus::Active);
    }

    public function deactivate(Request $request, Provider $provider): JsonResponse
    {
        return $this->move($request, $provider, ProviderStatus::Inactive);
    }

    public function suspend(Request $request, Provider $provider): JsonResponse
    {
        return $this->move($request, $provider, ProviderStatus::Suspended);
    }

    /** Moderation (FR-AD12): take a storefront offline without touching the provider's status or billing. */
    public function unpublishStorefront(Request $request, Provider $provider, StorefrontService $storefront): JsonResponse
    {
        $storefront->unpublish($provider, $request->user('api'), 'storefront.moderated');

        return ApiResponse::success(['published' => false]);
    }

    private function move(Request $request, Provider $provider, ProviderStatus $to, ?string $reason = null): JsonResponse
    {
        $this->lifecycle->transition($provider, $to, $request->user('api'), $reason);

        return ApiResponse::success(new AdminProviderResource($provider));
    }
}
