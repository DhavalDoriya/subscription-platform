<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListRequest;
use App\Models\ProviderCustomer;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The provider's own customers: exactly the people who subscribed (a provider_customers row exists only then).
 * Only name and email are shared; the global user record stays private (PRD 3.5).
 */
class SubscriberController extends Controller
{
    public function index(ListRequest $request): JsonResponse
    {
        $live = collect(SubscriptionStatus::cases())->filter->grantsAccess()->map->value->all();

        $subscribers = ProviderCustomer::query()->with('user')
            ->withCount(['subscriptions', 'subscriptions as active_subscriptions' => fn ($q) => $q->whereIn('status', $live)])
            ->whereHas('subscriptions', fn ($q) => $q->whereNotIn('status', ['incomplete', 'expired']))
            ->when($request->search(), fn ($query, string $search) => $query->matchingUser($search))
            ->orderBy($request->sortColumn(['created_at'], 'created_at'), $request->sortDirection())
            ->paginate($request->perPage());

        return ApiResponse::paginated($subscribers, fn (ProviderCustomer $link) => [
            'id' => $link->id,
            'name' => $link->user?->name,
            'email' => $link->user?->email,
            'subscriptions_count' => $link->subscriptions_count,
            'active_subscriptions' => $link->active_subscriptions,
            'since' => $link->created_at?->toIso8601String(),
        ]);
    }
}
