<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class AuthService
{
    public const COOKIE = 'refresh_token';

    private const MAX_ATTEMPTS = 5;

    public function __construct(private RefreshTokenService $refreshTokens) {}

    public function attempt(string $email, string $password, Request $request): User
    {
        $key = 'login:'.strtolower($email).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new ApiException(__('api.auth.too_many_attempts'), 429, 'too_many_attempts');
        }

        $user = User::where('email', $email)->first();

        if (! $user || ! password_verify($password, $user->password)) {
            RateLimiter::hit($key, 60);

            throw new ApiException(__('api.auth.invalid_credentials'), 401, 'invalid_credentials');
        }

        RateLimiter::clear($key);

        if ($user->isBlocked()) {
            throw new ApiException(__('api.auth.account_blocked'), 403, 'account_blocked');
        }

        return $user;
    }

    /** Access token + refresh cookie for a freshly authenticated user (login, register). */
    public function startSession(User $user, Request $request, int $status = 200): JsonResponse
    {
        [$plain] = $this->refreshTokens->issue($user, $request);

        return $this->respond($user, $plain, $status);
    }

    /** Rotate the refresh token from the cookie and mint a new access token. */
    public function refresh(Request $request): JsonResponse
    {
        [$user, $plain] = $this->refreshTokens->rotate($request->cookie(self::COOKIE), $request);

        return $this->respond($user, $plain);
    }

    public function logout(Request $request): void
    {
        $this->refreshTokens->revokeByPlain($request->cookie(self::COOKIE));
        Auth::guard('api')->logout();
    }

    public function logoutAll(User $user): void
    {
        $this->refreshTokens->revokeAllFor($user);
        Auth::guard('api')->logout();
    }

    public function forgetCookie(): SymfonyCookie
    {
        return Cookie::forget(self::COOKIE, $this->path(), config('auth.refresh.cookie_domain'));
    }

    private function respond(User $user, string $plainRefresh, int $status = 200): JsonResponse
    {
        $guard = Auth::guard('api');
        $token = $guard->login($user);

        return ApiResponse::success([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $guard->factory()->getTTL() * 60,
            'user' => (new UserResource($user->load('roles')))->resolve(),
        ], $status)->withCookie($this->cookie($plainRefresh));
    }

    private function cookie(string $plain): SymfonyCookie
    {
        return cookie(
            self::COOKIE,
            $plain,
            (int) config('auth.refresh.ttl_days') * 24 * 60,
            $this->path(),
            config('auth.refresh.cookie_domain'),
            (bool) config('auth.refresh.cookie_secure'),
            true,
            false,
            'lax',
        );
    }

    private function path(): string
    {
        return '/api/v1/auth';
    }
}
