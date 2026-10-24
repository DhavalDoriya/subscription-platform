<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/** Unsaved content to preview; anything left out falls back to the saved template. */
class PreviewEmailTemplateRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['nullable', 'string', 'max:255'],
            'html_body' => ['nullable', 'string', 'max:200000'],
            'text_body' => ['nullable', 'string', 'max:200000'],
        ];
    }
}
