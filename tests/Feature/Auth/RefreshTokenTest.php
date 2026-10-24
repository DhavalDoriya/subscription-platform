<?php

use App\Models\RefreshToken;
use App\Models\User;

function loggedIn(): array
{
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $response = loginAs($user);

    return [$user, $response->json('data.access_token'), refreshCookie($response)];
}

function refreshWith(string $token): Illuminate\Testing\TestResponse
{
    return test()->withCredentials()->withUnencryptedCookie('refresh_token', $token)->postJson('/api/v1/auth/refresh');
}

it('stores only a hash of the refresh token', function () {
    [, , $plain] = loggedIn();

    expect(RefreshToken::query()->where('token_hash', $plain)->exists())->toBeFalse()
        ->and(RefreshToken::query()->where('token_hash', hash('sha256', $plain))->exists())->toBeTrue();
});

it('FR-A rotates the refresh token and issues a new access token', function () {
    [, , $old] = loggedIn();

    $response = refreshWith($old)->assertOk()->assertJsonStructure(['data' => ['access_token', 'expires_in', 'user']]);
    $new = refreshCookie($response);

    expect($new)->not->toBe($old);
    $oldRow = RefreshToken::where('token_hash', hash('sha256', $old))->first();
    $newRow = RefreshToken::where('token_hash', hash('sha256', $new))->first();
    expect($oldRow->used_at)->not->toBeNull()
        ->and($oldRow->replaced_by)->toBe($newRow->id)
        ->and($newRow->family_id)->toBe($oldRow->family_id);
});

it('FR-A reuse of a rotated token revokes the whole family', function () {
    [, , $old] = loggedIn();
    $new = refreshCookie(refreshWith($old)->assertOk());

    refreshWith($old)->assertUnauthorized()->assertJsonPath('code', 'refresh_token_reused');

    refreshWith($new)->assertUnauthorized();
    expect(RefreshToken::whereNull('revoked_at')->count())->toBe(0);
});

it('rejects missing, unknown and expired refresh tokens', function () {
    [, , $plain] = loggedIn();

    $this->postJson('/api/v1/auth/refresh')->assertUnauthorized();
    refreshWith('unknown-token')->assertUnauthorized();

    RefreshToken::query()->update(['expires_at' => now()->subMinute()]);
    refreshWith($plain)->assertUnauthorized();
});

it('does not refresh for a soft-deleted user', function () {
    [$user, , $plain] = loggedIn();
    $user->delete();

    refreshWith($plain)->assertUnauthorized();
});

it('logout revokes the refresh token, blacklists the access token and clears the cookie', function () {
    [, $access, $plain] = loggedIn();

    $response = $this->withToken($access)->withCredentials()->withUnencryptedCookie('refresh_token', $plain)
        ->postJson('/api/v1/auth/logout')->assertNoContent();

    expect($response->getCookie('refresh_token', false)->isCleared())->toBeTrue()
        ->and(RefreshToken::where('token_hash', hash('sha256', $plain))->first()->revoked_at)->not->toBeNull();

    $this->app['auth']->forgetGuards();
    $this->withToken($access)->getJson('/api/v1/auth/me')->assertUnauthorized();
    refreshWith($plain)->assertUnauthorized();
});

it('logout-all revokes every session of the user', function () {
    [$user, $access] = loggedIn();
    loginAs($user);
    loginAs($user);
    expect(RefreshToken::whereNull('revoked_at')->count())->toBe(3);

    $this->app['auth']->forgetGuards();
    $this->withToken($access)->postJson('/api/v1/auth/logout-all')->assertNoContent();

    expect(RefreshToken::whereNull('revoked_at')->count())->toBe(0);
});

it('issues separate token families per login', function () {
    [$user] = loggedIn();
    loginAs($user);

    expect(RefreshToken::distinct('family_id')->count('family_id'))->toBe(2);
});
