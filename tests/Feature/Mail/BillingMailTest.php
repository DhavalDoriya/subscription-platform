<?php

use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Mail\StubTemplateMail;
use App\Services\Mail\TemplateMail;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    app(SettingsService::class)->set('stripe.connect_webhook_secret', 'whsec_connect');
});
use Illuminate\Support\Carbon;

function paidInvoiceObject(array $overrides = []): array
{
    return array_merge([
        'id' => 'in_r1', 'customer' => 'cus_1', 'subscription' => 'sub_r1', 'status' => 'paid', 'currency' => 'usd',
        'amount_due' => 2000, 'amount_paid' => 2000, 'hosted_invoice_url' => 'https://invoice.stripe.test/i/r1',
        'status_transitions' => ['paid_at' => 1700000900],
    ], $overrides);
}

function mailedTemplates(): array
{
    return collect(app(TemplateMail::class)->sent)->pluck('template')->all();
}

it('FR-E1 mails a receipt when an invoice is paid, once even if the event is replayed', function () {
    pendingSubscription(['status' => 'active', 'stripe_subscription_id' => 'sub_r1']);

    connectEvent('invoice.paid', paidInvoiceObject(), 1700000900, 'evt_r1')->assertOk();
    connectEvent('invoice.paid', paidInvoiceObject(), 1700000901, 'evt_r2')->assertOk();

    $sent = app(TemplateMail::class)->sent;
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['template'])->toBe('customer.receipt')
        ->and($sent[0]['variables']['amount'])->toBe('20.00 USD')
        ->and($sent[0]['variables']['invoice_url'])->toBe('https://invoice.stripe.test/i/r1')
        ->and($sent[0]['variables']['paid_at'])->toBe('2023-11-14')
        ->and(Invoice::sole()->receipt_sent_at)->not->toBeNull();
});

it('FR-E1 sends no receipt for a zero-amount invoice or a recovered one (the recovery notice covers it)', function () {
    pendingSubscription(['status' => 'active', 'stripe_subscription_id' => 'sub_r1']);
    connectEvent('invoice.paid', paidInvoiceObject(['id' => 'in_zero', 'amount_due' => 0, 'amount_paid' => 0]), 1700000900)->assertOk();
    expect(mailedTemplates())->toBe([]);

    connectEvent('invoice.payment_failed', paidInvoiceObject(['status' => 'open', 'amount_paid' => 0]), 1700000300)->assertOk();
    app()->instance(TemplateMail::class, new StubTemplateMail);
    connectEvent('invoice.paid', paidInvoiceObject(), 1700000950)->assertOk();

    expect(mailedTemplates())->not->toContain('customer.receipt');
});

it('FR-E1 warns the customer three days before a trial ends, once', function () {
    $subscription = pendingSubscription(['status' => 'trialing', 'trial_ends_at' => now()->addDays(2), 'current_period_end' => now()->addDays(2)]);

    $this->artisan('billing:send-reminders')->assertSuccessful();
    $this->artisan('billing:send-reminders')->assertSuccessful();

    $sent = app(TemplateMail::class)->sent;
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['template'])->toBe('customer.trial_ending')
        ->and($sent[0]['variables']['trial_ends_at'])->toBe(now()->addDays(2)->toDateString())
        ->and($subscription->fresh()->trial_reminder_for)->not->toBeNull();
});

it('FR-E1 does not warn about a trial that ends later or has ended', function () {
    pendingSubscription(['status' => 'trialing', 'trial_ends_at' => now()->addDays(10)]);
    $this->artisan('billing:send-reminders')->assertSuccessful();

    expect(mailedTemplates())->toBe([]);
});

it('FR-E1 reminds the customer three days before a renewal, once per period', function () {
    $subscription = pendingSubscription(['status' => 'active', 'current_period_end' => now()->addDays(2)]);

    $this->artisan('billing:send-reminders')->assertSuccessful();
    $this->artisan('billing:send-reminders')->assertSuccessful();
    expect(mailedTemplates())->toBe(['customer.upcoming_renewal']);

    // The next period gets its own reminder.
    Subscription::withoutGlobalScopes()->whereKey($subscription->id)->update(['current_period_end' => now()->addMonth()->addDays(2)]);
    Carbon::setTestNow(now()->addMonth());
    $this->artisan('billing:send-reminders')->assertSuccessful();

    expect(mailedTemplates())->toBe(['customer.upcoming_renewal', 'customer.upcoming_renewal']);
    Carbon::setTestNow();
});

it('FR-E1 sends no renewal reminder for a subscription that is canceling or past due', function (array $state) {
    pendingSubscription(['current_period_end' => now()->addDays(2)] + $state);
    $this->artisan('billing:send-reminders')->assertSuccessful();

    expect(mailedTemplates())->toBe([]);
})->with([
    'canceling' => [['status' => 'active', 'cancel_at_period_end' => true]],
    'past due' => [['status' => 'past_due']],
]);

it('FR-E1 the schedule sends reminders daily', function () {
    expect(collect(app(Schedule::class)->events())->pluck('command')->implode(' '))->toContain('billing:send-reminders');
});
