<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\ApiFormRequest;
use App\Services\Billing\SubscriptionService;
use Illuminate\Validation\Rule;

class ChangePlanRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'price_id' => ['required', 'uuid'],
            'timing' => ['nullable', Rule::in([SubscriptionService::IMMEDIATE, SubscriptionService::NEXT_CYCLE])],
        ];
    }

    public function timing(): string
    {
        return $this->validated('timing') ?? SubscriptionService::IMMEDIATE;
    }
}
