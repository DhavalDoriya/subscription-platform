<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Support\Auth\Impersonation;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends ApiFormRequest
{
    /** Credentials cannot be changed while an admin is impersonating the account. */
    public function authorize(): bool
    {
        return ! Impersonation::active();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:api'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ];
    }
}
