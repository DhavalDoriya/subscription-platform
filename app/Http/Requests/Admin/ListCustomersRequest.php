<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ListRequest;

class ListCustomersRequest extends ListRequest
{
    public const SORTABLE = ['name', 'email', 'created_at'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'blocked' => ['nullable', 'boolean']];
    }
}
