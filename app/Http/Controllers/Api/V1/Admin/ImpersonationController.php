<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    /** A normal access token for the target carrying `imp` = the admin; no refresh token, so it ends when the token does. */
    public function start(Request $request, string $user): JsonResponse
    {
        $admin = $request->user('api');
        $target = User::findOrFail($user);

        if ($target->hasAnyRole(['admin', 'super_admin'], 'api') || $target->isBlocked()) {
            throw new ApiException(__('api.admin.cannot_impersonate'), 409, 'cannot_impersonate');
        }

        activity()->causedBy($admin)->performedOn($target)->log('impersonation.started');

        $guard = Auth::guard('api');
        $token = $guard->claims(['imp' => $admin->id])->login($target);

        return ApiResponse::success([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $guard->factory()->getTTL() * 60,
            'user' => (new UserResource($target->load('roles')))->resolve(),
            'impersonator' => ['id' => $admin->id, 'name' => $admin->name],
        ]);
    }
}
