<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ListInvoicesRequest extends ListRequest
{
    public const SORTABLE = ['created_at', 'amount_due', 'status'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'status' => ['nullable', Rule::in(['draft', 'open', 'paid', 'void', 'uncollectible'])],
            'dunning' => ['nullable', Rule::in(['active'])], // only invoices whose failed payment is still being worked on
        ];
    }
}
