<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class StoreCommissionRuleRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'provider_id' => ['nullable', 'uuid', 'exists:providers,id'],
            'effective_from' => ['nullable', 'date'],
        ];
    }
}
