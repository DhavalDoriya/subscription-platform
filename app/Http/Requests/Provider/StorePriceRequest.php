<?php

namespace App\Http\Requests\Provider;

use App\Http\Requests\ApiFormRequest;
use App\Services\Settings\SettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

/** A price is money: integer minor units, within the admin's bounds, in the provider's one currency, on an allowed interval. */
class StorePriceRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $settings = app(SettingsService::class);
        $sometimes = $this->isMethod('PATCH') ? ['sometimes'] : [];

        return [
            'amount' => [...$sometimes, 'required', 'integer', 'min:'.$settings->get('provider.min_price'), 'max:'.$settings->get('provider.max_price')],
            'currency' => ['nullable', 'string', Rule::in([app(TenantContext::class)->current()->currency])],
            'interval' => [...$sometimes, 'required', Rule::in($settings->get('provider.allowed_intervals'))],
            'interval_count' => [...$sometimes, 'nullable', 'integer', 'min:1', 'max:52'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtolower($this->input('currency'))]);
        }
    }
}
