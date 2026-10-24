<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\PlanResource;
use App\Models\Plan;
use Illuminate\Http\Request;

/** @mixin Plan */
class AdminPlanResource extends PlanResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'provider' => $this->whenLoaded('provider', fn () => ['id' => $this->provider->id, 'name' => $this->provider->name, 'slug' => $this->provider->slug]),
        ];
    }
}
