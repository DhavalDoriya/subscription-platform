<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CommissionReportRequest;
use App\Http\Requests\Admin\ListCommissionRulesRequest;
use App\Http\Requests\Admin\StoreCommissionRuleRequest;
use App\Http\Resources\CommissionResource;
use App\Http\Resources\CommissionRuleResource;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Platform fee rates and the commission report (FR-CM1, FR-CM2, FR-CM4). Admin requests have no tenant, so queries see every provider. */
class CommissionController extends Controller
{
    public function rules(ListCommissionRulesRequest $request): JsonResponse
    {
        $rules = CommissionRule::query()->with('provider')
            ->when($request->validated('scope') === 'global', fn (Builder $query) => $query->whereNull('provider_id'))
            ->when($request->validated('provider_id'), fn (Builder $query, $id) => $query->where('provider_id', $id))
            ->orderBy($request->sortColumn(ListCommissionRulesRequest::SORTABLE, 'effective_from'), $request->validated('direction') ?? 'desc')
            ->paginate($request->perPage());

        return ApiResponse::paginated($rules, CommissionRuleResource::class);
    }

    /** A change is always a new rule from a date; old rules are history and are never edited. */
    public function store(StoreCommissionRuleRequest $request): JsonResponse
    {
        $rule = CommissionRule::create([
            'provider_id' => $request->validated('provider_id'),
            'percent' => $request->validated('percent'),
            'effective_from' => $request->validated('effective_from') ? Carbon::parse($request->validated('effective_from')) : now(),
            'created_by' => $request->user('api')->id,
        ]);

        activity()->causedBy($request->user('api'))->performedOn($rule)
            ->withProperties(['provider_id' => $rule->provider_id, 'percent' => (string) $rule->percent, 'effective_from' => $rule->effective_from->toIso8601String()])
            ->log('commission.rule_created');

        return ApiResponse::created(new CommissionRuleResource($rule->load('provider')));
    }

    /** Only a rate that has not started can be withdrawn. */
    public function destroy(Request $request, string $rule): JsonResponse
    {
        $model = CommissionRule::findOrFail($rule);

        if ($model->effective_from->lte(now())) {
            throw new ApiException(__('api.commission.rule_already_effective'), 409, 'rule_already_effective');
        }

        $model->delete();
        activity()->causedBy($request->user('api'))->performedOn($model)->log('commission.rule_removed');

        return ApiResponse::noContent();
    }

    public function report(CommissionReportRequest $request): JsonResponse
    {
        $filtered = fn () => Commission::query()
            ->when($request->validated('provider_id'), fn (Builder $query, $id) => $query->where('provider_id', $id))
            ->when($request->validated('from'), fn (Builder $query, $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($request->validated('to'), fn (Builder $query, $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()));

        $rows = $filtered()->with('provider')
            ->orderBy($request->sortColumn(CommissionReportRequest::SORTABLE, 'created_at'), $request->validated('direction') ?? 'desc')
            ->paginate($request->perPage());

        $totals = $filtered()->selectRaw('currency, sum(gross_amount) as gross, sum(refunded_amount) as refunded, sum(commission_amount) as commission, sum(commission_refunded) as commission_refunded')
            ->groupBy('currency')->orderBy('currency')->get()
            ->map(fn ($row) => [
                'currency' => $row->currency,
                'gross' => (int) $row->gross,
                'refunded' => (int) $row->refunded,
                'commission' => (int) $row->commission,
                'commission_refunded' => (int) $row->commission_refunded,
                'net_commission' => (int) $row->commission - (int) $row->commission_refunded,
            ])->values()->all();

        return ApiResponse::paginated($rows, CommissionResource::class, ['totals' => $totals]);
    }
}
