<?php

namespace App\Http\Requests\Provider;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdatePlanRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'features' => ['sometimes', 'array', 'max:20'],
            'features.*' => ['string', 'max:120'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:730'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'visibility' => ['sometimes', Rule::in(['public', 'unlisted'])],
        ];
    }
}
