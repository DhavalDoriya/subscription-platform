<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

class ListCommissionRulesRequest extends ListRequest
{
    public const SORTABLE = ['effective_from', 'percent', 'created_at'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'provider_id' => ['nullable', 'string', 'max:64'], 'scope' => ['nullable', Rule::in(['global'])]];
    }
}
