<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class CancelSubscriptionRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['when' => ['nullable', Rule::in(['period_end', 'now'])]];
    }

    public function atPeriodEnd(): bool
    {
        return ($this->validated('when') ?? 'period_end') === 'period_end';
    }
}
