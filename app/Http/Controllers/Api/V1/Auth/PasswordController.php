<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\Auth\RefreshTokenService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordController extends Controller
{
    /** Always answers the same way so the endpoint can't be used to find registered emails. */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        return ApiResponse::success(['message' => __('api.auth.reset_link_sent')]);
    }

    public function reset(ResetPasswordRequest $request, RefreshTokenService $refreshTokens): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($refreshTokens) {
                $user->forceFill(['password' => $password])->save();
                $refreshTokens->revokeAllFor($user);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new ApiException(__($status), 422, 'invalid_reset_token', ['token' => [__($status)]]);
        }

        return ApiResponse::success(['message' => __('api.auth.password_updated')]);
    }
}
