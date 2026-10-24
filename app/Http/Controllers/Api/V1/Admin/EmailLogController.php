<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListEmailLogsRequest;
use App\Http\Resources\Admin\EmailLogResource;
use App\Models\EmailLog;
use App\Services\Mail\EmailDelivery;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-E6: the delivery log with resend. */
class EmailLogController extends Controller
{
    public function index(ListEmailLogsRequest $request): JsonResponse
    {
        $logs = EmailLog::query()
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->search(), function ($query, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $query->where(fn ($q) => $q->whereRaw('lower(to_email) like ?', [$like])->orWhereRaw('lower(subject) like ?', [$like])->orWhereRaw('lower(template_key) like ?', [$like]));
            })
            ->orderBy($request->sortColumn(ListEmailLogsRequest::SORTABLE, 'created_at'), $request->validated('direction') ?? 'desc')
            ->paginate($request->perPage());

        return ApiResponse::paginated($logs, EmailLogResource::class);
    }

    public function show(EmailLog $log): JsonResponse
    {
        return ApiResponse::success(new EmailLogResource($log));
    }

    public function resend(Request $request, EmailLog $log, EmailDelivery $delivery): JsonResponse
    {
        if ($log->status !== EmailLog::FAILED) {
            throw new ApiException(__('api.mail.not_resendable'), 409, 'not_resendable');
        }

        activity()->causedBy($request->user('api'))->performedOn($log)->log('email.resent');

        return ApiResponse::success(new EmailLogResource($delivery->resend($log)));
    }
}
