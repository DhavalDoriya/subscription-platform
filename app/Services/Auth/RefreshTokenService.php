<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opaque, rotating refresh tokens. Only a sha256 hash is stored. Each token belongs to a
 * "family" (one per login); presenting an already-rotated token revokes the whole family.
 */
class RefreshTokenService
{
    /** @return array{0: string, 1: RefreshToken} plain token and its row */
    public function issue(User $user, Request $request, ?string $familyId = null): array
    {
        $plain = Str::random(64);

        $row = RefreshToken::create([
            'user_id' => $user->id,
            'family_id' => $familyId ?? (string) Str::uuid(),
            'token_hash' => RefreshToken::hash($plain),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            'ip' => $request->ip(),
            'expires_at' => now()->addDays((int) config('auth.refresh.ttl_days')),
        ]);

        return [$plain, $row];
    }

    /**
     * Validate and rotate. Returns the user plus the new plain token.
     *
     * @return array{0: User, 1: string}
     */
    public function rotate(?string $plain, Request $request): array
    {
        if (! $plain) {
            throw $this->invalid();
        }

        $token = RefreshToken::where('token_hash', RefreshToken::hash($plain))->first();

        if (! $token) {
            throw $this->invalid();
        }

        if ($token->used_at || $token->revoked_at) {
            $this->revokeFamily($token->family_id);

            throw new ApiException(__('api.auth.session_invalid'), 401, 'refresh_token_reused');
        }

        $user = $token->user;

        if ($token->isExpired() || ! $user || $user->isBlocked()) {
            throw $this->invalid();
        }

        return DB::transaction(function () use ($token, $user, $request) {
            [$newPlain, $new] = $this->issue($user, $request, $token->family_id);
            $token->update(['used_at' => now(), 'replaced_by' => $new->id]);

            return [$user, $newPlain];
        });
    }

    public function revokeByPlain(?string $plain): void
    {
        if ($plain && ($token = RefreshToken::where('token_hash', RefreshToken::hash($plain))->first())) {
            $this->revokeFamily($token->family_id);
        }
    }

    public function revokeFamily(string $familyId): void
    {
        RefreshToken::where('family_id', $familyId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function revokeAllFor(User $user): void
    {
        RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    private function invalid(): ApiException
    {
        return new ApiException(__('api.auth.session_invalid'), 401, 'invalid_refresh_token');
    }
}
