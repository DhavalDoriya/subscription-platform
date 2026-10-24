<?php

namespace App\Http\Resources\Admin;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Activity */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'causer' => $this->causer ? ['id' => $this->causer->id, 'name' => $this->causer->name, 'email' => $this->causer->email] : null,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'properties' => $this->properties,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
