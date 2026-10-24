<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'features' => $this->features ?? [],
            'status' => $this->status,
            'visibility' => $this->visibility,
            'trial_days' => $this->trial_days,
            'sort_order' => $this->sort_order,
            'prices' => PriceResource::collection($this->whenLoaded('prices', fn () => $this->prices->where('status', 'active')->values()))->resolve($request),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
