<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\ApiFormRequest;

class StartCheckoutRequest extends ApiFormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['price_id' => ['required', 'uuid']];
    }
}
