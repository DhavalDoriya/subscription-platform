<?php

namespace App\Services\Providers;

use App\Exceptions\ApiException;
use App\Models\Provider;
use App\Models\ProviderUser;
use App\Models\User;

/** The provider a signed-in user belongs to (v1: one per owner). */
class CurrentProvider
{
    public function for(User $user): Provider
    {
        $membership = ProviderUser::where('user_id', $user->id)->with('provider')->first();

        if (! $membership) {
            throw new ApiException(__('api.providers.not_found'), 404, 'provider_not_found');
        }

        return $membership->provider;
    }
}
