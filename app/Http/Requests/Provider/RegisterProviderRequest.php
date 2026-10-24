<?php

namespace App\Http\Requests\Provider;

use App\Http\Requests\ApiFormRequest;
use App\Services\Settings\SettingsService;
use App\Support\Tenancy\SlugRules;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegisterProviderRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:64'],
            'business_category' => ['required', 'string', 'max:100'],
            'country' => ['required', 'alpha', 'size:2'],
            'support_email' => ['required', 'email:rfc', 'max:255'],
            'currency' => ['nullable', 'string', Rule::in(app(SettingsService::class)->get('provider.allowed_currencies'))],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('slug') || ! is_string($this->input('slug'))) {
                return;
            }

            $reason = app(SlugRules::class)->check($this->input('slug'));

            if ($reason) {
                $validator->errors()->add('slug', __("api.providers.slug_{$reason}"));
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper($this->input('country'))]);
        }

        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtolower($this->input('currency'))]);
        }
    }
}
