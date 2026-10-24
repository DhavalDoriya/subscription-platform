<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListWebhookEventsRequest;
use App\Http\Resources\Admin\WebhookEventResource;
use App\Models\WebhookEvent;
use App\Services\Stripe\Webhooks\WebhookProcessor;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WebhookEventController extends Controller
{
    public function index(ListWebhookEventsRequest $request): JsonResponse
    {
        $events = WebhookEvent::query()
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->search(), function ($query, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $query->where(fn ($q) => $q->whereRaw('lower(type) like ?', [$like])->orWhereRaw('lower(stripe_event_id) like ?', [$like])->orWhereRaw('lower(account_id) like ?', [$like]));
            })
            ->orderBy($request->sortColumn(ListWebhookEventsRequest::SORTABLE, 'created_at'), $request->validated('direction') ?? 'desc')
            ->paginate($request->perPage());

        return ApiResponse::paginated($events, WebhookEventResource::class);
    }

    public function show(string $event): JsonResponse
    {
        return ApiResponse::success(new WebhookEventResource(WebhookEvent::findOrFail($event)));
    }

    /** Runs a failed (or never-handled) event through its handler again. Handlers are idempotent, so this is safe. */
    public function replay(Request $request, string $event, WebhookProcessor $processor): JsonResponse
    {
        $row = WebhookEvent::findOrFail($event);

        if (! in_array($row->status, ['failed', 'pending', 'ignored'], true)) {
            throw new ApiException(__('api.stripe.event_not_replayable'), 409, 'event_not_replayable');
        }

        activity()->causedBy($request->user('api'))->performedOn($row)->log('webhook.replayed');

        $row->update(['status' => 'pending']); // an ignored event may have gained a handler since

        try {
            $processor->handle($row->payload);
        } catch (Throwable $e) {
            throw new ApiException(__('api.stripe.replay_failed', ['error' => $e->getMessage()]), 422, 'replay_failed');
        }

        return ApiResponse::success(new WebhookEventResource($row->fresh()));
    }
}
