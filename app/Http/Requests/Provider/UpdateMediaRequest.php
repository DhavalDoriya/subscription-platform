<?php

namespace App\Http\Requests\Provider;

use App\Http\Requests\ApiFormRequest;

class UpdateMediaRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['alt' => ['nullable', 'string', 'max:255']];
    }
}
