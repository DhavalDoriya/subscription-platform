<?php

namespace App\Http\Requests\Provider;

use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

class ListPlansRequest extends ListRequest
{
    public const SORTABLE = ['name', 'sort_order', 'created_at', 'status'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'status' => ['nullable', Rule::in(['draft', 'active', 'archived'])]];
    }
}
