<?php

namespace App\Http\Requests;

use App\Enums\SubscriptionStatus;
use Illuminate\Validation\Rule;

/** Query params of the provider and admin subscription listings. */
class ListSubscriptionsRequest extends ListRequest
{
    public const SORTABLE = ['created_at', 'status', 'amount', 'current_period_end'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['nullable', Rule::enum(SubscriptionStatus::class)],
            'plan_id' => ['nullable', 'uuid'],
            'provider_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return array{status: ?string, plan_id: ?string, provider_id: ?string, search: ?string} */
    public function filters(): array
    {
        return [
            'status' => $this->validated('status'),
            'plan_id' => $this->validated('plan_id'),
            'provider_id' => $this->validated('provider_id'),
            'search' => $this->search(),
        ];
    }
}
