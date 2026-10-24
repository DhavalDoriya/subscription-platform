<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

class ListAdminPlansRequest extends ListRequest
{
    public const SORTABLE = ['name', 'status', 'created_at'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['nullable', Rule::in(['draft', 'active', 'archived'])],
            'provider_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
