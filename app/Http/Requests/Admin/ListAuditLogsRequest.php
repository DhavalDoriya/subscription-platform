<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ListRequest;

class ListAuditLogsRequest extends ListRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'event' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'string', 'max:64'],
            'causer_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
