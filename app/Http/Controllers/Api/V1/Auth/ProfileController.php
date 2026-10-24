<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Requests\Auth\UploadAvatarRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user('api');
        $emailChanged = $request->changesEmail();

        $user->fill($request->safe()->only(['name', 'email']));

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return $this->respond($user);
    }

    /** Revokes every session (PRD 3.4) and hands the caller a fresh one so this device stays signed in. */
    public function password(ChangePasswordRequest $request, AuthService $auth): JsonResponse
    {
        $user = $request->user('api');
        $user->forceFill(['password' => $request->validated('password')])->save();
        $auth->logoutAll($user);

        return $auth->startSession($user, $request);
    }

    public function storeAvatar(UploadAvatarRequest $request): JsonResponse
    {
        $user = $request->user('api');
        $previous = $user->avatar_path;

        $user->forceFill(['avatar_path' => $request->file('avatar')->store('avatars', 'public')])->save();
        $this->deleteFile($previous);

        return $this->respond($user);
    }

    public function destroyAvatar(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $this->deleteFile($user->avatar_path);
        $user->forceFill(['avatar_path' => null])->save();

        return $this->respond($user);
    }

    private function respond(User $user): JsonResponse
    {
        return ApiResponse::success(new UserResource($user->load('roles')));
    }

    private function deleteFile(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
