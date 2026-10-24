<?php

namespace App\Http\Requests\Admin;

use App\Enums\ProviderStatus;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

class ListProvidersRequest extends ListRequest
{
    public const SORTABLE = ['name', 'slug', 'status', 'created_at'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'status' => ['nullable', Rule::enum(ProviderStatus::class)]];
    }
}
