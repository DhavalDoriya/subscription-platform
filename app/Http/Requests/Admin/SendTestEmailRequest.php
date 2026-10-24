<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class SendTestEmailRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['to' => ['required', 'email', 'max:255']];
    }
}
