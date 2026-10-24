<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class UploadAvatarRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
