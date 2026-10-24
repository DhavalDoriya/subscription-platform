<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

function validRegistration(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ], $overrides);
}

it('FR-A1 registers a customer, returns tokens and a refresh cookie', function () {
    Notification::fake();

    $response = $this->postJson('/api/v1/auth/register', validRegistration())
        ->assertCreated()
        ->assertJsonStructure(['data' => ['access_token', 'token_type', 'expires_in', 'user' => ['id', 'name', 'email', 'roles']]])
        ->assertJsonPath('data.user.roles', ['customer'])
        ->assertJsonPath('data.token_type', 'bearer')
        ->assertJsonMissingPath('data.user.password');

    $cookie = $response->getCookie('refresh_token', false);
    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getPath())->toBe('/api/v1/auth')
        ->and(strtolower((string) $cookie->getSameSite()))->toBe('lax');

    $user = User::where('email', 'jane@example.com')->first();
    expect(Str::isUuid($user->id))->toBeTrue()
        ->and(Hash::check('Password123!', $user->password))->toBeTrue();
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('FR-A1 rejects duplicate emails and weak passwords', function () {
    User::factory()->create(['email' => 'jane@example.com']);

    $this->postJson('/api/v1/auth/register', validRegistration())->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->postJson('/api/v1/auth/register', validRegistration(['email' => 'new@example.com', 'password' => 'short', 'password_confirmation' => 'short']))
        ->assertUnprocessable()->assertJsonValidationErrors('password');
});

it('FR-A1 logs in with valid credentials', function () {
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');

    $response = loginAs($user)->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.expires_in', config('jwt.ttl') * 60);

    expect(refreshCookie($response))->not->toBeEmpty();
});

it('puts roles in the access token claims', function () {
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole('admin');
    $token = loginAs($user)->json('data.access_token');

    $payload = json_decode(base64_decode(explode('.', $token)[1]), true);

    expect($payload['sub'])->toBe($user->id)
        ->and($payload['roles'])->toBe(['admin'])
        ->and($payload)->toHaveKey('jti');
});

it('FR-A1 rejects wrong credentials without revealing which part was wrong', function () {
    $user = User::factory()->create(['password' => 'Password123!']);

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'nope'])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'invalid_credentials');
    $this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'nope'])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'invalid_credentials');
});

it('FR-A5 locks out after 5 failed attempts, even for the right password', function () {
    $user = User::factory()->create(['password' => 'Password123!']);

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bad'])->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'too_many_attempts');
});

it('does not log in soft-deleted users', function () {
    $user = User::factory()->create(['password' => 'Password123!']);
    $user->delete();

    loginAs($user)->assertUnauthorized();
});

it('returns the current user from /auth/me and requires a token', function () {
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');

    $this->getJson('/api/v1/auth/me')->assertUnauthorized();

    $token = loginAs($user)->json('data.access_token');
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', $user->email);
});

it('FR-A5 throttles the auth endpoints per IP, with the limit coming from config', function () {
    config(['auth.rate_limits.auth' => 3]);

    foreach (range(1, 3) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever'])->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever'])->assertStatus(429);
});
