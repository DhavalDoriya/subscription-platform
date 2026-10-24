<?php

namespace App\Http\Resources\Admin;

use App\Models\EmailTemplate;
use App\Services\Mail\TemplateCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmailTemplate */
class EmailTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detail = $request->route('template') !== null;

        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'subject' => $this->subject,
            'enabled' => $this->enabled,
            'version' => $this->version,
            'updated_at' => $this->updated_at?->toIso8601String(),
            // The list stays light; the editor gets the bodies and the documented variables.
            'html_body' => $this->when($detail, $this->html_body),
            'text_body' => $this->when($detail, $this->text_body),
            'variables' => $this->when($detail, fn () => (object) app(TemplateCatalog::class)->variables($this->key)),
            'samples' => $this->when($detail, fn () => (object) app(TemplateCatalog::class)->samples($this->key)),
        ];
    }
}
