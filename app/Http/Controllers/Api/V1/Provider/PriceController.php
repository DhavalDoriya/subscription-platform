<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\StorePriceRequest;
use App\Http\Resources\PriceResource;
use App\Models\Plan;
use App\Models\Price;
use App\Services\Plans\PlanService;
use App\Support\Api\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class PriceController extends Controller
{
    public function __construct(private PlanService $plans, private TenantContext $tenancy) {}

    public function store(StorePriceRequest $request, string $plan): JsonResponse
    {
        $price = $this->plans->addPrice($this->tenancy->current(), Plan::findOrFail($plan), $request->validated());

        return ApiResponse::created(new PriceResource($price));
    }

    /** On a synced price this answers with the replacement (a new id); the old price is archived. */
    public function update(StorePriceRequest $request, string $plan, string $price): JsonResponse
    {
        $model = Plan::findOrFail($plan);
        $changed = $this->plans->changePrice($this->tenancy->current(), $model, $this->price($model, $price), $request->validated());

        return ApiResponse::success(new PriceResource($changed));
    }

    public function destroy(string $plan, string $price): JsonResponse
    {
        $model = Plan::findOrFail($plan);
        $this->plans->removePrice($this->tenancy->current(), $model, $this->price($model, $price));

        return ApiResponse::noContent();
    }

    private function price(Plan $plan, string $id): Price
    {
        return Price::where('plan_id', $plan->id)->findOrFail($id);
    }
}
