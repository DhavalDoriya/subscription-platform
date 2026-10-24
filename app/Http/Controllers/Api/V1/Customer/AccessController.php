<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\ProviderUser;
use App\Models\Subscription;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /subscriptions/{id}/access (FR-C8): lets a provider's product ask "may this person use it right now?".
 * Readable by the subscriber, the provider's team and admins; anyone else gets a 404 so ids are not discoverable.
 */
class AccessController extends Controller
{
    public function show(Request $request, string $subscription): JsonResponse
    {
        $user = $request->user('api');
        $model = Subscription::with('providerCustomer')->findOrFail($subscription);

        $allowed = $model->providerCustomer->user_id === $user->id
            || $user->hasAnyRole(['admin', 'super_admin'])
            || ProviderUser::where('provider_id', $model->provider_id)->where('user_id', $user->id)->exists();

        abort_unless($allowed, 404);

        return ApiResponse::success([
            'subscription_id' => $model->id,
            'status' => $model->status->value,
            'has_access' => $model->hasAccess(),
            'plan_id' => $model->plan_id,
            'cancel_at_period_end' => $model->cancel_at_period_end,
            'current_period_end' => $model->current_period_end?->toIso8601String(),
        ]);
    }
}
