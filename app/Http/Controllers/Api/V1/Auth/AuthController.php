<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());
        $user->assignRole('customer');
        $user->sendEmailVerificationNotification();

        return $this->auth->startSession($user, $request, 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->auth->attempt($request->validated('email'), $request->validated('password'), $request);

        return $this->auth->startSession($user, $request);
    }

    public function refresh(Request $request): JsonResponse
    {
        return $this->auth->refresh($request);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request);

        return ApiResponse::noContent()->withCookie($this->auth->forgetCookie());
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->auth->logoutAll($request->user('api'));

        return ApiResponse::noContent()->withCookie($this->auth->forgetCookie());
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user('api')->load('roles')));
    }
}
