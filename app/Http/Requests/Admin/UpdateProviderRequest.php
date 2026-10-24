<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Support\Tenancy\SlugRules;
use Illuminate\Validation\Validator;

/** Admin edits to a provider. The slug is immutable for owners after approval, so only admins change it, with the same rules. */
class UpdateProviderRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:64'],
            'support_email' => ['sometimes', 'required', 'email:rfc', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $slug = $this->input('slug');

            if ($validator->errors()->has('slug') || ! is_string($slug) || $slug === $this->route('provider')->slug) {
                return;
            }

            if ($reason = app(SlugRules::class)->check($slug)) {
                $validator->errors()->add('slug', __("api.providers.slug_{$reason}"));
            }
        }];
    }
}
