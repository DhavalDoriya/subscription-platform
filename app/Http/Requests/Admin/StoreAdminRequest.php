<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Models\User;
use Illuminate\Validation\Rules\Password;

class StoreAdminRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:'.User::class.',email'],
            'password' => ['required', Password::defaults()],
        ];
    }
}
