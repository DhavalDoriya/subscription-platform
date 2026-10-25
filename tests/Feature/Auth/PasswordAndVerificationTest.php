<?php

use App\Models\RefreshToken;
use App\Models\User;
use App\Services\Mail\TemplateMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

it('FR-A3 sends a reset link pointing at the frontend and does not reveal unknown emails', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertOk();
    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'ghost@example.com'])->assertOk();

    $sent = app(TemplateMail::class)->sent;
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['template'])->toBe('auth.password_reset')
        ->and($sent[0]['to'])->toBe($user->email);
    $url = $sent[0]['variables']['reset_url'];
    expect($url)->toStartWith(rtrim(config('app.frontend_url'), '/').'/reset-password?')
        ->and($url)->toContain('token=')->toContain(urlencode($user->email));
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
        ->and(RefreshToken::whereNull('revoked_at')->count())->toBe(0)
        ->and(collect(app(TemplateMail::class)->sent)->pluck('template')->all())->toBe(['auth.password_changed']);
});

it('FR-A3 rejects an invalid reset token', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => $user->email, 'token' => 'bad',
        'password' => 'NewPassword456!', 'password_confirmation' => 'NewPassword456!',
    ])->assertUnprocessable();
});

it('FR-A2 verification email links to the frontend, which forwards to the signed API route', function () {
    $user = User::factory()->unverified()->create();
    $user->sendEmailVerificationNotification();

    $sent = app(TemplateMail::class)->sent;
    expect($sent[0]['template'])->toBe('auth.verify_email')->and($sent[0]['to'])->toBe($user->email);
    $url = $sent[0]['variables']['verify_url'];
    expect($url)->toStartWith(rtrim(config('app.frontend_url'), '/').'/verify-email?')
        ->and($url)->toContain('signature=')->toContain('id='.$user->id);
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
    $user = User::factory()->unverified()->create(['password' => 'Password123!']);
    $token = loginAs($user)->json('data.access_token');

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/auth/email/resend')->assertOk();

    expect(collect(app(TemplateMail::class)->sent)->pluck('template'))->toContain('auth.verify_email');
});
