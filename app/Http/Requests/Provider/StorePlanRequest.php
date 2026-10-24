<?php

namespace App\Http\Requests\Provider;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StorePlanRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'features' => ['nullable', 'array', 'max:20'],
            'features.*' => ['string', 'max:120'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:730'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'visibility' => ['nullable', Rule::in(['public', 'unlisted'])],
        ];
    }
}
