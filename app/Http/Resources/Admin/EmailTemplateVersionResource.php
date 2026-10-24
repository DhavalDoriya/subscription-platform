<?php

namespace App\Http\Resources\Admin;

use App\Models\EmailTemplateVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmailTemplateVersion */
class EmailTemplateVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'subject' => $this->subject,
            'html_body' => $this->html_body,
            'text_body' => $this->text_body,
            'author' => $this->author?->name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
