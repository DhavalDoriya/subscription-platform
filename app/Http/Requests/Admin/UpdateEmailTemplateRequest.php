<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Models\EmailTemplate;
use App\Services\Mail\TemplateRenderer;
use Illuminate\Validation\Validator;

class UpdateEmailTemplateRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'html_body' => ['required', 'string', 'max:200000'],
            'text_body' => ['required', 'string', 'max:200000'],
            'enabled' => ['required', 'boolean'],
        ];
    }

    /** FR-E2: a template may only use the placeholders documented for its key. */
    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var EmailTemplate $template */
            $template = $this->route('template');
            $renderer = app(TemplateRenderer::class);

            foreach (['subject', 'html_body', 'text_body'] as $field) {
                $unknown = $renderer->unknownVariables($template->key, (string) $this->input($field));
                if ($unknown) {
                    $validator->errors()->add($field, __('api.mail.unknown_variables', ['variables' => implode(', ', $unknown)]));
                }
            }
        }];
    }
}
