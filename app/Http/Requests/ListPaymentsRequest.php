<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ListPaymentsRequest extends ListRequest
{
    public const SORTABLE = ['created_at', 'amount', 'status'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'status' => ['nullable', Rule::in(['pending', 'succeeded', 'failed', 'refunded', 'partially_refunded'])]];
    }
}
