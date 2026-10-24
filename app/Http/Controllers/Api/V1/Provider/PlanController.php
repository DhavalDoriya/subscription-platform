<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\ListPlansRequest;
use App\Http\Requests\Provider\StorePlanRequest;
use App\Http\Requests\Provider\UpdatePlanRequest;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Services\Plans\PlanService;
use App\Support\Api\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Plans of the signed-in provider. The `provider.tenant` middleware scopes every query to them, and rows are looked up
 * here (not by route-model binding, which runs earlier) so another provider's id is simply a 404.
 */
class PlanController extends Controller
{
    public function __construct(private PlanService $plans, private TenantContext $tenancy) {}

    public function index(ListPlansRequest $request): JsonResponse
    {
        $plans = Plan::query()->with('prices')
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->search(), fn ($query, $search) => $query->whereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%']))
            ->orderBy($request->sortColumn(ListPlansRequest::SORTABLE, 'sort_order'), $request->sortDirection())
            ->orderBy('created_at')
            ->paginate($request->perPage());

        return ApiResponse::paginated($plans, PlanResource::class);
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        return ApiResponse::created(new PlanResource($this->plans->create($this->tenancy->current(), $request->validated())->load('prices')));
    }

    public function show(string $plan): JsonResponse
    {
        return ApiResponse::success(new PlanResource(Plan::with('prices')->findOrFail($plan)));
    }

    public function update(UpdatePlanRequest $request, string $plan): JsonResponse
    {
        $model = Plan::with('prices')->findOrFail($plan);

        return ApiResponse::success(new PlanResource($this->plans->update($this->tenancy->current(), $model, $request->validated())->load('prices')));
    }

    public function publish(string $plan): JsonResponse
    {
        return ApiResponse::success(new PlanResource($this->plans->publish($this->tenancy->current(), Plan::findOrFail($plan))->load('prices')));
    }

    public function archive(string $plan): JsonResponse
    {
        return ApiResponse::success(new PlanResource($this->plans->archive($this->tenancy->current(), Plan::findOrFail($plan))->load('prices')));
    }

    public function destroy(string $plan): JsonResponse
    {
        $this->plans->delete(Plan::findOrFail($plan));

        return ApiResponse::noContent();
    }
}
