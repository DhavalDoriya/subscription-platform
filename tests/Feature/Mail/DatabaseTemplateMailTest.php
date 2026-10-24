<?php

use App\Jobs\SendEmailLog;
use App\Mail\RenderedMail;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Provider;
use App\Models\Setting;
use App\Services\Dunning\DunningService;
use App\Services\Mail\DatabaseTemplateMail;
use App\Services\Mail\EmailDelivery;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\TemplateCatalog;
use App\Services\Mail\TemplateMail;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    app(TemplateCatalog::class)->seedAll();
    $this->mailer = app(DatabaseTemplateMail::class);
});

$vars = ['customer.name' => 'Ada', 'provider.name' => 'Acme', 'plan.name' => 'Gold', 'amount' => '20.00 USD', 'retry_date' => '2026-11-05', 'update_payment_url' => 'https://x.test/fix'];

it('FR-E6 the container resolves the database-backed mailer', function () {
    // The Pest suite swaps in the recording stub; dropping it shows the production binding.
    app()->forgetInstance(TemplateMail::class);

    expect(app(TemplateMail::class))->toBeInstanceOf(DatabaseTemplateMail::class);
});

it('FR-E6 queues the email, logs it and delivers it rendered from the template', function () use ($vars) {
    Mail::fake();
    $provider = Provider::factory()->create();

    $this->mailer->send('dunning.payment_failed', 'ada@example.com', $vars, $provider->id);

    $log = EmailLog::firstOrFail();
    expect($log->status)->toBe('sent')   // queue is sync in tests
        ->and($log->to_email)->toBe('ada@example.com')
        ->and($log->template_key)->toBe('dunning.payment_failed')
        ->and($log->provider_id)->toBe($provider->id)
        ->and($log->subject)->toContain('Acme')
        ->and($log->html_body)->toContain('Ada')
        ->and($log->sent_at)->not->toBeNull();
    Mail::assertSent(RenderedMail::class, fn (RenderedMail $mail) => $mail->hasTo('ada@example.com') && str_contains($mail->subject ?? '', 'Acme'));
});

it('FR-E6 puts the email on the queue instead of sending inline', function () use ($vars) {
    Queue::fake();
    Mail::fake();

    $this->mailer->send('dunning.payment_failed', 'ada@example.com', $vars);

    Queue::assertPushed(SendEmailLog::class);
    expect(EmailLog::firstOrFail()->status)->toBe('queued');
    Mail::assertNothingSent();
});

it('FR-E1 a disabled template is not sent but is noted in the log', function () use ($vars) {
    Mail::fake();
    EmailTemplate::where('key', 'dunning.payment_failed')->update(['enabled' => false]);

    $this->mailer->send('dunning.payment_failed', 'ada@example.com', $vars);

    Mail::assertNothingSent();
    expect(EmailLog::firstOrFail()->status)->toBe('skipped');
});

it('FR-E1 an edited template is what the next email uses', function () use ($vars) {
    Mail::fake();
    EmailTemplate::where('key', 'dunning.payment_failed')->update(['subject' => 'Oops {{customer.name}}']);

    $this->mailer->send('dunning.payment_failed', 'ada@example.com', $vars);

    expect(EmailLog::firstOrFail()->subject)->toBe('Oops Ada');
});

it('FR-E2 an unknown variable passed by the caller is ignored safely', function () {
    Mail::fake();

    $this->mailer->send('dunning.payment_failed', 'ada@example.com', ['customer.name' => 'Ada', 'rogue' => 'x']);

    expect(EmailLog::firstOrFail()->status)->toBe('sent');
});

it('FR-E3 creates a missing default template on first use', function () {
    Mail::fake();
    EmailTemplate::query()->forceDelete();

    $this->mailer->send('auth.welcome', 'ada@example.com', ['user.name' => 'Ada']);

    expect(EmailTemplate::where('key', 'auth.welcome')->exists())->toBeTrue()
        ->and(EmailLog::firstOrFail()->status)->toBe('sent');
});

it('FR-E6 refuses a template key that does not exist', function () {
    expect(fn () => $this->mailer->send('nope.nothing', 'ada@example.com', []))->toThrow(InvalidArgumentException::class);
});

it('FR-E6 records a failed delivery with its error, and resending delivers it', function () use ($vars) {
    // Point the platform mailer at a closed port so the real SMTP attempt fails.
    $settings = app(SettingsService::class);
    $settings->set('mail.driver', 'smtp');
    $settings->set('mail.host', '127.0.0.1');
    $settings->set('mail.port', 1);

    $this->mailer->send('dunning.payment_failed', 'ada@example.com', $vars);

    $log = EmailLog::firstOrFail();
    expect($log->status)->toBe('failed')->and($log->error)->not->toBeEmpty()->and($log->attempts)->toBe(1);

    Mail::fake();
    $settings->set('mail.driver', 'default');
    app(EmailDelivery::class)->resend($log);

    expect($log->fresh()->status)->toBe('sent')->and($log->fresh()->error)->toBeNull()->and($log->fresh()->attempts)->toBe(2);
    Mail::assertSent(RenderedMail::class, 1);
});

it('FR-E5 builds the mailer from stored settings and never exposes the password', function () {
    $settings = app(SettingsService::class);
    $settings->set('mail.driver', 'smtp');
    $settings->set('mail.host', 'smtp.example.com');
    $settings->set('mail.port', 587);
    $settings->set('mail.username', 'user');
    $settings->set('mail.password', 'p@ss');
    $settings->set('mail.encryption', 'tls');
    $settings->set('mail.from_address', 'billing@example.com');
    $settings->set('mail.from_name', 'Billing');

    $config = app(MailConfigurator::class)->transportConfig();

    expect($config)->toMatchArray(['transport' => 'smtp', 'host' => 'smtp.example.com', 'port' => 587, 'username' => 'user', 'password' => 'p@ss'])
        ->and(Setting::where('key', 'mail.password')->value('value'))->not->toContain('p@ss');
    expect(app(MailConfigurator::class)->from())->toBe(['address' => 'billing@example.com', 'name' => 'Billing']);
});

it('FR-E5 uses the server mail configuration when the driver is left on default', function () {
    expect(app(MailConfigurator::class)->transportConfig())->toBeNull();
});

it('FR-R6 FR-E1 the next dunning email reflects an edit to the payment-failed template', function () {
    Mail::fake();
    app()->forgetInstance(TemplateMail::class); // use the real mailer instead of the recording stub
    EmailTemplate::where('key', 'dunning.payment_failed')->update(['subject' => 'Heads up {{customer.name}} about {{provider.name}}']);
    $invoice = openInvoice();

    app(DunningService::class)->recordFailure($invoice, 'insufficient_funds', 'Your card has insufficient funds.', 'pi_1');

    $log = EmailLog::where('template_key', 'dunning.payment_failed')->firstOrFail();
    $customer = $invoice->providerCustomer->user;
    expect($log->subject)->toBe("Heads up {$customer->name} about {$invoice->provider->name}")
        ->and($log->to_email)->toBe($customer->email)
        ->and($log->provider_id)->toBe($invoice->provider_id)
        ->and($log->status)->toBe('sent');
});
