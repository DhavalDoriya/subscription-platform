<?php

use App\Mail\RenderedMail;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\Mail\TemplateCatalog;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    app(TemplateCatalog::class)->seedAll();
    $admin = User::factory()->create(['password' => 'Password123!'])->assignRole('admin');
    $this->withToken(loginAs($admin)->json('data.access_token'));
    app('auth')->forgetGuards();
    $this->admin = $admin;
});

function template(string $key = 'dunning.payment_failed'): EmailTemplate
{
    return EmailTemplate::where('key', $key)->firstOrFail();
}

it('FR-AD8 keeps the email manager behind admin roles', function () {
    asGuest();
    $this->getJson('/api/v1/admin/email-templates')->assertUnauthorized();

    $customer = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    app('auth')->forgetGuards();
    $this->withToken(loginAs($customer)->json('data.access_token'));
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/admin/email-templates')->assertForbidden();
    $this->getJson('/api/v1/admin/email-logs')->assertForbidden();
});

it('FR-E1 lists templates with search and sorting', function () {
    $res = $this->getJson('/api/v1/admin/email-templates?search=dunning&sort=key&per_page=100')->assertOk();

    expect(collect($res->json('data'))->pluck('key')->all())->toContain('dunning.payment_failed', 'dunning.final_notice')
        ->and(collect($res->json('data'))->pluck('key')->contains('auth.welcome'))->toBeFalse()
        ->and($res->json('meta.total'))->toBeGreaterThan(5);
});

it('FR-E2 shows a template with its documented variables and sample data', function () {
    $t = template();

    $res = $this->getJson("/api/v1/admin/email-templates/{$t->id}")->assertOk();

    expect($res->json('data.key'))->toBe('dunning.payment_failed')
        ->and($res->json('data.variables'))->toHaveKey('customer.name')
        ->and($res->json('data.variables')['customer.name'])->toBeString()
        ->and($res->json('data.html_body'))->toContain('{{customer.name}}')
        ->and($res->json('data.version'))->toBe(1);
});

it('FR-E1 saving an edit stores a new version and records who made it', function () {
    $t = template();

    $this->putJson("/api/v1/admin/email-templates/{$t->id}", [
        'subject' => 'Payment problem for {{plan.name}}', 'html_body' => '<p>Hi {{customer.name}}</p>', 'text_body' => 'Hi {{customer.name}}', 'enabled' => true,
    ])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.subject', 'Payment problem for {{plan.name}}');

    $versions = $this->getJson("/api/v1/admin/email-templates/{$t->id}/versions")->assertOk()->json('data');
    expect($versions)->toHaveCount(2)
        ->and($versions[0]['version'])->toBe(2)
        ->and($versions[1]['subject'])->not->toBe('Payment problem for {{plan.name}}')
        ->and($t->fresh()->updated_by)->toBe($this->admin->id);
});

it('FR-E2 rejects a placeholder the template does not document', function () {
    $t = template();

    $this->putJson("/api/v1/admin/email-templates/{$t->id}", [
        'subject' => 'Hi {{customer.name}}', 'html_body' => '<p>{{bogus.thing}}</p>', 'text_body' => 'x', 'enabled' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('html_body');

    expect($t->fresh()->version)->toBe(1);
});

it('FR-E1 toggling enabled alone does not need a new version of the text', function () {
    $t = template();

    $this->putJson("/api/v1/admin/email-templates/{$t->id}", [
        'subject' => $t->subject, 'html_body' => $t->html_body, 'text_body' => $t->text_body, 'enabled' => false,
    ])->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.version', 1);
});

it('FR-E1 restores an older version as a new version', function () {
    $t = template();
    $original = $t->subject;
    $this->putJson("/api/v1/admin/email-templates/{$t->id}", ['subject' => 'Changed', 'html_body' => $t->html_body, 'text_body' => $t->text_body, 'enabled' => true])->assertOk();

    $this->postJson("/api/v1/admin/email-templates/{$t->id}/versions/1/restore")->assertOk()
        ->assertJsonPath('data.version', 3)->assertJsonPath('data.subject', $original);
    $this->postJson("/api/v1/admin/email-templates/{$t->id}/versions/99/restore")->assertNotFound();
});

it('FR-E2 previews unsaved content with sample data', function () {
    $t = template();

    $res = $this->postJson("/api/v1/admin/email-templates/{$t->id}/preview", [
        'subject' => 'Hello {{customer.name}}', 'html_body' => '<b>{{amount}}</b> {{nope}}', 'text_body' => 'T {{amount}}',
    ])->assertOk();

    expect($res->json('data.subject'))->toStartWith('Hello ')->not->toContain('{{')
        ->and($res->json('data.html'))->toStartWith('<b>')->not->toContain('{{')
        ->and($res->json('data.text'))->toStartWith('T ');
});

it('FR-E2 test-sends a template to an address and logs it, even when it is disabled', function () {
    Mail::fake();
    $t = template();
    $t->update(['enabled' => false]);

    $this->postJson("/api/v1/admin/email-templates/{$t->id}/test", ['to' => 'me@example.com'])
        ->assertOk()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.to_email', 'me@example.com');

    Mail::assertSent(RenderedMail::class, 1);
    $this->postJson("/api/v1/admin/email-templates/{$t->id}/test", ['to' => 'bad'])->assertUnprocessable();
});

it('FR-E6 lists the delivery log with filters and shows one email', function () {
    $sent = EmailLog::factory()->create(['status' => 'sent', 'to_email' => 'a@example.com']);
    $failed = EmailLog::factory()->create(['status' => 'failed', 'error' => 'boom', 'to_email' => 'b@example.com']);

    $all = $this->getJson('/api/v1/admin/email-logs')->assertOk();
    expect($all->json('meta.total'))->toBe(2)->and($all->json('data.0'))->not->toHaveKey('html_body');
    $only = $this->getJson('/api/v1/admin/email-logs?status=failed')->assertOk();
    expect(collect($only->json('data'))->pluck('id')->all())->toBe([$failed->id]);
    expect($this->getJson('/api/v1/admin/email-logs?search=a@example')->json('data.0.id'))->toBe($sent->id);
    $this->getJson("/api/v1/admin/email-logs/{$failed->id}")->assertOk()->assertJsonPath('data.error', 'boom')->assertJsonStructure(['data' => ['html_body', 'text_body']]);
});

it('FR-E6 resends only failed emails', function () {
    Mail::fake();
    $failed = EmailLog::factory()->create(['status' => 'failed', 'error' => 'boom']);
    $sent = EmailLog::factory()->create(['status' => 'sent']);

    $this->postJson("/api/v1/admin/email-logs/{$failed->id}/resend")->assertOk()->assertJsonPath('data.status', 'sent');
    $this->postJson("/api/v1/admin/email-logs/{$sent->id}/resend")->assertStatus(409);
    Mail::assertSent(RenderedMail::class, 1);
});
