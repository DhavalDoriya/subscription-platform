<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /** Signed route; the frontend forwards id/hash/expires/signature from the emailed link. */
    public function verify(string $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403, __('api.auth.invalid_verification_link'));

        $user->hasVerifiedEmail() || $user->markEmailAsVerified();

        return ApiResponse::success(['message' => __('api.auth.email_verified')]);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user('api');

        $user->hasVerifiedEmail() || $user->sendEmailVerificationNotification();

        return ApiResponse::success(['message' => __('api.auth.verification_sent')]);
    }
}
