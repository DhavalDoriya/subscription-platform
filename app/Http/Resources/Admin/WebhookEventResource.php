<?php

namespace App\Http\Resources\Admin;

use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WebhookEvent */
class WebhookEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stripe_event_id' => $this->stripe_event_id,
            'type' => $this->type,
            'account_id' => $this->account_id,
            'livemode' => $this->livemode,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'error' => $this->error,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // The list stays light; the detail view adds the payload.
            'payload' => $this->when($request->routeIs('*show'), $this->payload),
        ];
    }
}
