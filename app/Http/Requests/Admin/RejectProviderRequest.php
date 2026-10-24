<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class RejectProviderRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
