<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'is_blocked' => $this->isBlocked(),
            'blocked_at' => $this->blocked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'providers' => $this->whenLoaded('providerLinks', fn () => $this->providerLinks->map(fn ($link) => [
                'id' => $link->provider->id,
                'name' => $link->provider->name,
                'slug' => $link->provider->slug,
                'joined_at' => $link->created_at->toIso8601String(),
            ])->values()->all()),
        ];
    }
}
