<?php

namespace App\Http\Requests\Provider;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class RefundPaymentRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['nullable', 'integer', 'min:1'], // minor units; empty = everything that is left
            'reason' => ['nullable', Rule::in(['requested_by_customer', 'duplicate', 'fraudulent'])],
        ];
    }
}
