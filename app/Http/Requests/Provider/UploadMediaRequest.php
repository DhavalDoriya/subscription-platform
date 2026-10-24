<?php

namespace App\Http\Requests\Provider;

use App\Http\Requests\ApiFormRequest;
use App\Services\Settings\SettingsService;

class UploadMediaRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKb = (int) app(SettingsService::class)->get('media.max_upload_kb');

        return ['file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', "max:{$maxKb}"]];
    }
}
