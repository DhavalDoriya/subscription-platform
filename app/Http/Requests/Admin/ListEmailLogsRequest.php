<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

class ListEmailLogsRequest extends ListRequest
{
    public const SORTABLE = ['created_at', 'status', 'to_email', 'template_key'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'status' => ['nullable', Rule::in(['queued', 'sent', 'failed', 'skipped'])]];
    }
}
