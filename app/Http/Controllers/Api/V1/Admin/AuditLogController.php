<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAuditLogsRequest;
use App\Http\Resources\Admin\AuditLogResource;
use App\Models\Activity;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    public function index(ListAuditLogsRequest $request): JsonResponse
    {
        $logs = Activity::query()->with('causer')
            ->when($request->validated('event'), fn ($query, $event) => $query->where('description', $event))
            ->when($request->validated('subject_id'), fn ($query, $id) => $query->where('subject_id', $id))
            ->when($request->validated('causer_id'), fn ($query, $id) => $query->where('causer_id', $id))
            ->when($request->search(), fn ($query, $search) => $query->where('description', 'like', "%{$search}%"))
            ->orderBy($request->sortColumn(['created_at', 'description'], 'created_at'), $request->validated('direction') ?? 'desc')
            ->paginate($request->perPage());

        return ApiResponse::paginated($logs, AuditLogResource::class);
    }
}
