<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** A blocked user's still-valid access token stops working immediately, not at expiry. */
class EnsureNotBlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->bearerToken() ? Auth::guard('api')->user() : null;

        if ($user?->isBlocked()) {
            throw new ApiException(__('api.auth.account_blocked'), 403, 'account_blocked');
        }

        return $next($request);
    }
}
