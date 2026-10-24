<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Models\User;
use App\Support\Auth\Impersonation;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends ApiFormRequest
{
    /** Credentials cannot be changed while an admin is impersonating the account. */
    public function authorize(): bool
    {
        return ! Impersonation::active();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->user('api');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email:rfc', 'max:255', Rule::unique(User::class, 'email')->ignore($user->id)],
            // Changing the sign-in email is sensitive, so it needs the current password.
            'current_password' => [
                Rule::requiredIf(fn () => $this->changesEmail()),
                'string',
                'current_password:api',
            ],
        ];
    }

    public function changesEmail(): bool
    {
        return $this->has('email') && strtolower((string) $this->input('email')) !== strtolower($this->user('api')->email);
    }
}
