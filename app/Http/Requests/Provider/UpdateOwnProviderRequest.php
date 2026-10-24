<?php

namespace App\Http\Requests\Provider;

use App\Http\Requests\ApiFormRequest;
use App\Services\Settings\SettingsService;
use DateTimeZone;
use Illuminate\Validation\Rule;

/** What a provider owner may edit about their own account. Slug, status and Stripe fields are deliberately absent. */
class UpdateOwnProviderRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'support_email' => ['sometimes', 'required', 'email:rfc', 'max:255'],
            'timezone' => ['sometimes', 'required', Rule::in(DateTimeZone::listIdentifiers())],
            'currency' => ['sometimes', 'required', 'string', Rule::in(app(SettingsService::class)->get('provider.allowed_currencies'))],
            'branding' => ['sometimes', 'array'],
            'branding.primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'tax_enabled' => ['sometimes', 'boolean', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value && ! app(SettingsService::class)->get('stripe.tax_enabled')) {
                    $fail(__('api.providers.tax_unavailable'));
                }
            }],
            'legal' => ['sometimes', 'array'],
            'legal.terms_url' => ['nullable', 'url:http,https', 'max:2048'],
            'legal.privacy_url' => ['nullable', 'url:http,https', 'max:2048'],
            'legal.refund_url' => ['nullable', 'url:http,https', 'max:2048'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtolower($this->input('currency'))]);
        }
    }
}
