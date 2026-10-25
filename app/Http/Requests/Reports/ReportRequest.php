<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ReportRequest extends ApiFormRequest
{
    public const EXPORTS = ['revenue', 'churn', 'failures', 'payments', 'commission'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'group' => ['nullable', Rule::in(['day', 'month'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'provider_id' => ['nullable', 'uuid'],
            'type' => ['nullable', Rule::in(self::EXPORTS)],
        ];
    }

    public function group(): string
    {
        return $this->validated('group') ?? 'month';
    }

    public function from(): Carbon
    {
        return $this->validated('from') ? Carbon::parse($this->validated('from'))->startOfDay() : now()->subMonths(12)->startOfMonth();
    }

    public function to(): Carbon
    {
        return $this->validated('to') ? Carbon::parse($this->validated('to'))->endOfDay() : now()->endOfDay();
    }

    /** Admins may narrow a report to one provider; a provider's own queries are already limited to their tenant. */
    public function providerId(): ?string
    {
        return $this->validated('provider_id');
    }
}
