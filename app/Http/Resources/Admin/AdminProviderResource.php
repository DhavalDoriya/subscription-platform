<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\ProviderResource;
use App\Models\Provider;
use Illuminate\Http\Request;

/** @mixin Provider */
class AdminProviderResource extends ProviderResource
{
    public function toArray(Request $request): array
    {
        $owner = $this->owner();

        return [
            ...parent::toArray($request),
            'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email] : null,
            'customers_count' => $this->customers_count ?? $this->customers()->count(),
        ];
    }
}
