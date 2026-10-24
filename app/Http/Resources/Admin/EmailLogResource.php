<?php

namespace App\Http\Resources\Admin;

use App\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmailLog */
class EmailLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_key' => $this->template_key,
            'provider_id' => $this->provider_id,
            'to_email' => $this->to_email,
            'subject' => $this->subject,
            'status' => $this->status,
            'error' => $this->error,
            'attempts' => $this->attempts,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // The list stays light; the detail view adds what was sent.
            'html_body' => $this->when($request->route('log') !== null, $this->html_body),
            'text_body' => $this->when($request->route('log') !== null, $this->text_body),
        ];
    }
}
