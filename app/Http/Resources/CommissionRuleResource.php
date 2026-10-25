<?php

namespace App\Http\Resources;

use App\Models\CommissionRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CommissionRule */
class CommissionRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? ['id' => $this->provider->id, 'name' => $this->provider->name, 'slug' => $this->provider->slug] : null),
            'percent' => (string) $this->percent,
            'effective_from' => $this->effective_from->toIso8601String(),
            'in_effect' => $this->effective_from->lte(now()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
