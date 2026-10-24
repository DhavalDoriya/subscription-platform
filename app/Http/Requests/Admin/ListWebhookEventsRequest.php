<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

class ListWebhookEventsRequest extends ListRequest
{
    public const SORTABLE = ['created_at', 'type', 'status'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'status' => ['nullable', Rule::in(['pending', 'processed', 'failed', 'ignored'])]];
    }
}
