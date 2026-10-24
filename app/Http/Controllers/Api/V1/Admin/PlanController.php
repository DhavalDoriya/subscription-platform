<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminPlansRequest;
use App\Http\Resources\Admin\AdminPlanResource;
use App\Models\Plan;
use App\Services\Plans\PlanService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Plans of every provider (FR-AD5). Admin requests have no tenant, so the tenant scope does not narrow these queries. */
class PlanController extends Controller
{
    public function index(ListAdminPlansRequest $request): JsonResponse
    {
        $plans = Plan::query()->with(['provider', 'prices'])
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->validated('provider_id'), fn ($query, $id) => $query->where('provider_id', $id))
            ->when($request->search(), fn ($query, $search) => $query->whereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%']))
            ->orderBy($request->sortColumn(ListAdminPlansRequest::SORTABLE, 'created_at'), $request->validated('direction') ?? 'desc')
            ->paginate($request->perPage());

        return ApiResponse::paginated($plans, AdminPlanResource::class);
    }

    public function archive(Request $request, string $plan, PlanService $plans): JsonResponse
    {
        $model = Plan::with('provider')->findOrFail($plan);
        $plans->archive($model->provider, $model);

        activity()->causedBy($request->user('api'))->performedOn($model)->log('plan.force_archived');

        return ApiResponse::success(new AdminPlanResource($model->load('prices')));
    }
}
