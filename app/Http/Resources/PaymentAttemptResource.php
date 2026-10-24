<?php

namespace App\Http\Resources;

use App\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentAttempt */
class PaymentAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attempt_number' => $this->attempt_number,
            'kind' => $this->kind,
            'status' => $this->status,
            'decline_code' => $this->decline_code,
            'decline_type' => $this->decline_type?->value,
            'failure_message' => $this->failure_message,
            'attempted_at' => $this->attempted_at?->toIso8601String(),
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
        ];
    }
}
