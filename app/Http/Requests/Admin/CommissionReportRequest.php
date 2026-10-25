<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ListRequest;

class CommissionReportRequest extends ListRequest
{
    public const SORTABLE = ['created_at', 'commission_amount', 'gross_amount'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'provider_id' => ['nullable', 'string', 'max:64'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']];
    }
}
