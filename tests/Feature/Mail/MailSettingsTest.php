<?php

use App\Mail\RenderedMail;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

function mailAdmin(string $role): void
{
    $user = User::factory()->create(['password' => 'Password123!'])->assignRole($role);
    test()->withToken(loginAs($user)->json('data.access_token'));
    app('auth')->forgetGuards();
}

it('FR-E5 stores the mail password encrypted and never returns it', function () {
    mailAdmin('super_admin');

    $res = $this->putJson('/api/v1/admin/settings', ['settings' => ['mail' => [
        'driver' => 'smtp', 'host' => 'smtp.example.com', 'port' => 587, 'username' => 'u', 'password' => 'SuperSecret1', 'encryption' => 'tls',
        'from_address' => 'billing@example.com', 'from_name' => 'Billing',
    ]]])->assertOk();

    expect($res->json('data.mail.host.value'))->toBe('smtp.example.com')
        ->and($res->json('data.mail.password'))->toBe(['is_set' => true])
        ->and($res->getContent())->not->toContain('SuperSecret1');
    expect(Setting::where('key', 'mail.password')->value('value'))->not->toContain('SuperSecret1');
    expect($this->getJson('/api/v1/admin/settings')->getContent())->not->toContain('SuperSecret1');
});

it('FR-E5 only a super admin can change mail secrets', function () {
    mailAdmin('admin');

    $this->putJson('/api/v1/admin/settings', ['settings' => ['mail' => ['password' => 'x']]])->assertForbidden();
    $this->putJson('/api/v1/admin/settings', ['settings' => ['mail' => ['host' => 'smtp.example.com']]])->assertOk();
});

it('FR-E5 validates the mail settings', function () {
    mailAdmin('super_admin');

    $this->putJson('/api/v1/admin/settings', ['settings' => ['mail' => ['driver' => 'carrier-pigeon', 'from_address' => 'nope', 'port' => 70000]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['settings.mail.driver', 'settings.mail.from_address', 'settings.mail.port']);
});

it('FR-E5 sends a test email through the configured mailer', function () {
    mailAdmin('admin');
    Mail::fake();

    $this->postJson('/api/v1/admin/mail/test', ['to' => 'me@example.com'])->assertOk()->assertJsonPath('data.sent', true);

    Mail::assertSent(RenderedMail::class, fn (RenderedMail $m) => $m->hasTo('me@example.com'));
    $this->postJson('/api/v1/admin/mail/test', ['to' => 'bad'])->assertUnprocessable();
});

it('FR-E5 reports why a test email failed', function () {
    mailAdmin('super_admin');
    $this->putJson('/api/v1/admin/settings', ['settings' => ['mail' => ['driver' => 'smtp', 'host' => '127.0.0.1', 'port' => 1]]])->assertOk();

    $this->postJson('/api/v1/admin/mail/test', ['to' => 'me@example.com'])->assertStatus(502)->assertJsonPath('code', 'mail_test_failed');
});
