<?php

namespace App\Http\Resources;

use App\Models\DunningEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DunningEvent */
class DunningEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'template' => $this->payload['template'] ?? null,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
