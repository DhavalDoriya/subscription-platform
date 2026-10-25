<?php

use App\Models\RefreshToken;
use App\Models\User;
use App\Services\Mail\TemplateMail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

function signedIn(array $attributes = []): array
{
    $user = User::factory()->create(array_merge(['password' => 'Password123!'], $attributes))->assignRole('customer');
    $access = loginAs($user)->json('data.access_token');
    app('auth')->forgetGuards();

    return [$user, $access];
}

it('FR-A8 requires authentication for every profile endpoint', function () {
    $this->patchJson('/api/v1/auth/profile')->assertUnauthorized();
    $this->putJson('/api/v1/auth/password')->assertUnauthorized();
    $this->postJson('/api/v1/auth/avatar')->assertUnauthorized();
    $this->deleteJson('/api/v1/auth/avatar')->assertUnauthorized();
});

it('FR-A8 updates the name without touching verification', function () {
    [$user, $access] = signedIn();

    $this->withToken($access)->patchJson('/api/v1/auth/profile', ['name' => 'New Name'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name');

    expect($user->fresh()->name)->toBe('New Name')->and($user->fresh()->email_verified_at)->not->toBeNull();
});

it('FR-A8 changing the email needs the current password, unverifies it and sends a new link', function () {
    [$user, $access] = signedIn();

    $this->withToken($access)->patchJson('/api/v1/auth/profile', ['email' => 'new@example.com'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');

    $this->withToken($access)->patchJson('/api/v1/auth/profile', ['email' => 'new@example.com', 'current_password' => 'wrong'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');

    $this->withToken($access)->patchJson('/api/v1/auth/profile', ['email' => 'new@example.com', 'current_password' => 'Password123!'])
        ->assertOk()->assertJsonPath('data.email', 'new@example.com')->assertJsonPath('data.email_verified_at', null);

    expect($user->fresh()->email_verified_at)->toBeNull();
    $sent = app(TemplateMail::class)->sent;
    expect($sent)->toHaveCount(1)->and($sent[0]['template'])->toBe('auth.verify_email')->and($sent[0]['to'])->toBe('new@example.com');
});

it('FR-A8 rejects an email that another account uses', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    [, $access] = signedIn();

    $this->withToken($access)->patchJson('/api/v1/auth/profile', ['email' => 'taken@example.com', 'current_password' => 'Password123!'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('FR-A8 changes the password, revokes every old session and issues a new one', function () {
    [$user, $access] = signedIn();
    loginAs($user); // a second device
    $this->app['auth']->forgetGuards();

    $response = $this->withToken($access)->putJson('/api/v1/auth/password', [
        'current_password' => 'Password123!',
        'password' => 'NewPassword456!',
        'password_confirmation' => 'NewPassword456!',
    ])->assertOk()->assertJsonStructure(['data' => ['access_token', 'user']]);

    expect(Hash::check('NewPassword456!', $user->fresh()->password))->toBeTrue()
        ->and(RefreshToken::whereNull('revoked_at')->count())->toBe(1)
        ->and($response->getCookie('refresh_token', false))->not->toBeNull();
});

it('FR-A8 refuses a password change with the wrong current password', function () {
    [$user, $access] = signedIn();

    $this->withToken($access)->putJson('/api/v1/auth/password', [
        'current_password' => 'nope',
        'password' => 'NewPassword456!',
        'password_confirmation' => 'NewPassword456!',
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

    expect(Hash::check('Password123!', $user->fresh()->password))->toBeTrue();
});

it('FR-A8 uploads an avatar to the public disk and exposes its url', function () {
    Storage::fake('public');
    [$user, $access] = signedIn();

    $this->withToken($access)->postJson('/api/v1/auth/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 200, 200)])
        ->assertOk()->assertJsonPath('data.avatar_url', fn ($url) => is_string($url) && str_contains($url, 'avatars/'));

    Storage::disk('public')->assertExists($user->fresh()->avatar_path);
});

it('FR-A8 rejects non-images and oversized avatars, and replaces the old file', function () {
    Storage::fake('public');
    [$user, $access] = signedIn();

    $this->withToken($access)->postJson('/api/v1/auth/avatar', ['avatar' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
        ->assertUnprocessable()->assertJsonValidationErrors('avatar');
    $this->withToken($access)->postJson('/api/v1/auth/avatar', ['avatar' => UploadedFile::fake()->image('big.png')->size(3000)])
        ->assertUnprocessable()->assertJsonValidationErrors('avatar');

    $this->withToken($access)->postJson('/api/v1/auth/avatar', ['avatar' => UploadedFile::fake()->image('a.png')])->assertOk();
    $first = $user->fresh()->avatar_path;
    $this->app['auth']->forgetGuards();
    $this->withToken($access)->postJson('/api/v1/auth/avatar', ['avatar' => UploadedFile::fake()->image('b.png')])->assertOk();

    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($user->fresh()->avatar_path);
});

it('FR-A8 removes the avatar', function () {
    Storage::fake('public');
    [$user, $access] = signedIn();
    $this->withToken($access)->postJson('/api/v1/auth/avatar', ['avatar' => UploadedFile::fake()->image('a.png')])->assertOk();
    $path = $user->fresh()->avatar_path;
    $this->app['auth']->forgetGuards();

    $this->withToken($access)->deleteJson('/api/v1/auth/avatar')->assertOk()->assertJsonPath('data.avatar_url', null);

    Storage::disk('public')->assertMissing($path);
    expect($user->fresh()->avatar_path)->toBeNull();
});
