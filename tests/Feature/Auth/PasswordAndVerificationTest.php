<?php

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

it('FR-A3 sends a reset link pointing at the frontend and does not reveal unknown emails', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertOk();
    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'ghost@example.com'])->assertOk();

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;

        return str_starts_with($url, rtrim(config('app.frontend_url'), '/').'/reset-password?')
            && str_contains($url, 'token=') && str_contains($url, urlencode($user->email));
    });
});

it('FR-A3 resets the password and revokes every session', function () {
    $user = User::factory()->create(['password' => 'Password123!']);
    loginAs($user);
    $token = Password::createToken($user);

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => $user->email, 'token' => $token,
        'password' => 'NewPassword456!', 'password_confirmation' => 'NewPassword456!',
    ])->assertOk();

    expect(Hash::check('NewPassword456!', $user->fresh()->password))->toBeTrue()
        ->and(RefreshToken::whereNull('revoked_at')->count())->toBe(0);
});

it('FR-A3 rejects an invalid reset token', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => $user->email, 'token' => 'bad',
        'password' => 'NewPassword456!', 'password_confirmation' => 'NewPassword456!',
    ])->assertUnprocessable();
});

it('FR-A2 verification email links to the frontend, which forwards to the signed API route', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $user->sendEmailVerificationNotification();

    Notification::assertSentTo($user, VerifyEmail::class, function ($notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;

        return str_starts_with($url, rtrim(config('app.frontend_url'), '/').'/verify-email?')
            && str_contains($url, 'signature=') && str_contains($url, 'id='.$user->id);
    });
});

it('FR-A2 verifies the email through the signed API route', function () {
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

    $this->getJson($url)->assertOk();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('FR-A2 rejects a tampered verification link', function () {
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

    $this->getJson($url.'x')->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('FR-A2 lets an authenticated user request a new verification email', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['password' => 'Password123!']);
    $token = loginAs($user)->json('data.access_token');

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/auth/email/resend')->assertOk();

    Notification::assertSentTo($user, VerifyEmail::class);
});
