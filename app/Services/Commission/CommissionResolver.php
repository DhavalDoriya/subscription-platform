<?php

namespace App\Services\Commission;

use App\Models\CommissionRule;
use App\Models\Provider;
use Illuminate\Support\Carbon;

/**
 * The platform fee rate for a provider at a point in time (FR-CM1, FR-CM2): the provider's own latest rule that has
 * started, else the latest global rule that has started, else 0. A rule only counts from its `effective_from`.
 */
class CommissionResolver
{
    /** @return string percent with two decimals, e.g. `10.00` */
    public function rateFor(Provider $provider, ?Carbon $at = null): string
    {
        $at ??= now();

        $rule = $this->latest($provider->id, $at) ?? $this->latest(null, $at);

        return $rule ? (string) $rule->percent : '0.00';
    }

    private function latest(?string $providerId, Carbon $at): ?CommissionRule
    {
        return CommissionRule::query()->where('provider_id', $providerId)->where('effective_from', '<=', $at)
            ->orderByDesc('effective_from')->orderByDesc('created_at')->first();
    }
}
