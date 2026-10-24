<?php

use App\Enums\DeclineType;
use App\Enums\SubscriptionStatus;
use App\Jobs\RetryInvoicePayment;
use App\Models\DunningEvent;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Services\Dunning\DunningService;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

const T0 = '2026-11-02 09:00:00';

beforeEach(fn () => Carbon::setTestNow(T0));
afterEach(fn () => Carbon::setTestNow());

function dunning(): DunningService
{
    return app(DunningService::class);
}

function failFirstPayment(Invoice $invoice, ?string $code = 'insufficient_funds', ?string $intent = 'pi_first'): PaymentAttempt
{
    return dunning()->recordFailure($invoice, $code, "Declined ({$code}).", $intent);
}

it('FR-R4 the first failure is recorded with its decline and the next retry', function () {
    $invoice = openInvoice();

    $attempt = failFirstPayment($invoice);

    expect($attempt->attempt_number)->toBe(1)
        ->and($attempt->kind)->toBe('initial')
        ->and($attempt->status)->toBe('failed')
        ->and($attempt->decline_code)->toBe('insufficient_funds')
        ->and($attempt->decline_type)->toBe(DeclineType::Soft)
        ->and($attempt->next_retry_at->toDateTimeString())->toBe('2026-11-03 09:00:00');

    $invoice->refresh();
    expect($invoice->dunning_status)->toBe('retrying')
        ->and($invoice->first_failed_at->toDateTimeString())->toBe(T0)
        ->and($invoice->next_retry_at->toDateTimeString())->toBe('2026-11-03 09:00:00')
        ->and($invoice->subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($invoice->dunningEvents->pluck('type')->all())->toBe(['failure_notice', 'retry_scheduled']);
});

it('FR-R4 recording the same payment intent twice keeps one attempt', function () {
    $invoice = openInvoice();

    failFirstPayment($invoice);
    failFirstPayment($invoice);

    expect(PaymentAttempt::count())->toBe(1)->and(DunningEvent::where('type', 'failure_notice')->count())->toBe(1);
});

it('FR-R2 a soft decline is retried on the schedule until the retries run out, then grace begins', function () {
    $invoice = openInvoice();
    foreach (range(1, 4) as $n) {
        fakeStripe()->declineInvoice('in_open1', 'insufficient_funds', "pi_retry{$n}");
    }
    failFirstPayment($invoice);

    // +1, +3, +5, +7 days after the first failure, each at the exact moment it falls due.
    foreach ([['2026-11-03 09:00:00', '2026-11-05 09:00:00'], ['2026-11-05 09:00:00', '2026-11-07 09:00:00'], ['2026-11-07 09:00:00', '2026-11-09 09:00:00']] as [$now, $next]) {
        Carbon::setTestNow($now);
        dunning()->retry($invoice->fresh());
        expect($invoice->fresh()->next_retry_at->toDateTimeString())->toBe($next)->and($invoice->fresh()->dunning_status)->toBe('retrying');
    }

    Carbon::setTestNow('2026-11-09 09:00:00');
    dunning()->retry($invoice->fresh());

    $invoice->refresh();
    expect(PaymentAttempt::pluck('attempt_number')->sort()->values()->all())->toBe([1, 2, 3, 4, 5])
        ->and($invoice->dunning_status)->toBe('exhausted')
        ->and($invoice->next_retry_at)->toBeNull()
        ->and($invoice->subscription->status)->toBe(SubscriptionStatus::Grace)
        ->and($invoice->subscription->grace_ends_at->toDateTimeString())->toBe('2026-11-16 09:00:00')
        ->and($invoice->dunningEvents->pluck('type')->last())->toBe('grace_started')
        ->and(fakeStripe()->callsTo('payInvoice'))->toHaveCount(4);
});

it('FR-R3 a hard decline is never retried, asks for a new card at once and starts grace', function () {
    $invoice = openInvoice();

    $attempt = failFirstPayment($invoice, 'stolen_card');

    $invoice->refresh();
    expect($attempt->next_retry_at)->toBeNull()
        ->and($invoice->dunning_status)->toBe('awaiting_customer')
        ->and($invoice->next_retry_at)->toBeNull()
        ->and($invoice->dunningEvents->pluck('type')->all())->toBe(['failure_notice', 'new_card_requested', 'grace_started'])
        ->and($invoice->subscription->status)->toBe(SubscriptionStatus::Grace);

    Carbon::setTestNow('2026-11-10 09:00:00');
    dunning()->retry($invoice);
    expect(fakeStripe()->callsTo('payInvoice'))->toBeEmpty();
});

it('FR-R3 authentication required sends the customer a link instead of retrying', function () {
    $invoice = openInvoice();

    failFirstPayment($invoice, 'authentication_required');

    $invoice->refresh();
    expect($invoice->dunning_status)->toBe('awaiting_customer')
        ->and($invoice->next_retry_at)->toBeNull()
        ->and($invoice->dunningEvents->pluck('type')->all())->toContain('auth_required')
        ->and($invoice->dunningEvents->where('type', 'auth_required')->first()->payload)->toHaveKey('url');
});

it('FR-R7 a successful retry recovers the invoice and the subscription', function () {
    $invoice = openInvoice();
    failFirstPayment($invoice);

    Carbon::setTestNow('2026-11-03 09:00:00');
    dunning()->retry($invoice->fresh());

    $invoice->refresh();
    expect($invoice->status)->toBe('paid')
        ->and($invoice->dunning_status)->toBe('recovered')
        ->and($invoice->next_retry_at)->toBeNull()
        ->and($invoice->amount_paid)->toBe(2000)
        ->and($invoice->subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($invoice->subscription->grace_ends_at)->toBeNull()
        ->and($invoice->attempts->last()->status)->toBe('succeeded')
        ->and($invoice->dunningEvents->pluck('type')->last())->toBe('recovered');

    $calls = fakeStripe()->callsTo('payInvoice');
    expect($calls)->toHaveCount(1)->and($calls[0]['invoice'])->toBe('in_open1')->and($calls[0]['idempotency_key'])->toBe("dunning-{$invoice->id}-2");
});

it('FR-R7 two workers on the same invoice charge once', function () {
    $invoice = openInvoice();
    failFirstPayment($invoice);
    Carbon::setTestNow('2026-11-03 09:00:00');

    // The other worker holds the invoice's lock: this one backs off without touching Stripe.
    $lock = Cache::lock("dunning:invoice:{$invoice->id}", 30);
    expect($lock->get())->toBeTrue();
    dunning()->retry($invoice->fresh());
    expect(fakeStripe()->callsTo('payInvoice'))->toBeEmpty();
    $lock->release();

    // Once it is done, the late worker finds nothing due.
    dunning()->retry($invoice->fresh());
    dunning()->retry($invoice->fresh());
    expect(fakeStripe()->callsTo('payInvoice'))->toHaveCount(1);
});

it('FR-R7 an invoice that is not due yet is left alone', function () {
    $invoice = openInvoice();
    failFirstPayment($invoice);

    Carbon::setTestNow('2026-11-02 21:00:00');
    dunning()->retry($invoice->fresh());

    expect(fakeStripe()->callsTo('payInvoice'))->toBeEmpty()->and(PaymentAttempt::count())->toBe(1);
});

it('FR-R7 billing is not retried while the provider is paused', function () {
    $invoice = openInvoice();
    failFirstPayment($invoice);
    $invoice->subscription->provider->forceFill(['status' => 'inactive'])->save();

    Carbon::setTestNow('2026-11-03 09:00:00');
    dunning()->retry($invoice->fresh());

    expect(fakeStripe()->callsTo('payInvoice'))->toBeEmpty()->and($invoice->fresh()->dunning_status)->toBe('retrying');
});

it('FR-R2 follows an admin-configured schedule', function () {
    app(SettingsService::class)->set('dunning.retry_days', ['2', '4']);
    $invoice = openInvoice();

    $attempt = failFirstPayment($invoice);

    expect($attempt->next_retry_at->toDateTimeString())->toBe('2026-11-04 09:00:00');
});

it('FR-R5 when grace ends the subscription is canceled', function () {
    $invoice = openInvoice();
    failFirstPayment($invoice, 'stolen_card');
    $subscription = $invoice->subscription;

    Carbon::setTestNow('2026-11-09 08:59:00');
    expect(dunning()->expireGrace())->toBe(0);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Grace);

    Carbon::setTestNow('2026-11-09 09:00:00');
    expect(dunning()->expireGrace())->toBe(1);

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Canceled)
        ->and($subscription->grace_ends_at)->toBeNull()
        ->and($invoice->fresh()->dunningEvents->pluck('type')->last())->toBe('canceled')
        ->and(fakeStripe()->callsTo('cancelSubscription'))->toHaveCount(1);
});

it('FR-R5 the final action can be to pause instead', function () {
    app(SettingsService::class)->set('dunning.final_action', 'pause');
    $invoice = openInvoice();
    failFirstPayment($invoice, 'stolen_card');

    Carbon::setTestNow('2026-11-09 09:00:00');
    dunning()->expireGrace();

    $subscription = $invoice->subscription->fresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Paused)
        ->and($invoice->fresh()->dunningEvents->pluck('type')->last())->toBe('paused')
        ->and(fakeStripe()->callsTo('updateSubscription')[0]['params'])->toHaveKey('pause_collection')
        ->and(fakeStripe()->callsTo('cancelSubscription'))->toBeEmpty();
});

it('FR-R5 zero grace days applies the final action straight away', function () {
    app(SettingsService::class)->set('dunning.grace_days', 0);
    $invoice = openInvoice();

    failFirstPayment($invoice, 'stolen_card');

    expect($invoice->subscription->fresh()->status)->toBe(SubscriptionStatus::Canceled);
});

it('FR-R5 a payment during grace ends grace', function () {
    $invoice = openInvoice();
    failFirstPayment($invoice, 'stolen_card');

    dunning()->markRecovered($invoice->fresh());

    $subscription = $invoice->subscription->fresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Active)->and($subscription->grace_ends_at)->toBeNull()
        ->and($invoice->fresh()->dunning_status)->toBe('recovered');
    expect(dunning()->expireGrace())->toBe(0);
});

it('FR-R9 marking resolved stops the dunning without claiming a payment', function () {
    $invoice = openInvoice();
    failFirstPayment($invoice);

    dunning()->resolve($invoice->fresh());

    $invoice->refresh();
    expect($invoice->dunning_status)->toBe('resolved')->and($invoice->next_retry_at)->toBeNull()
        ->and($invoice->subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($invoice->dunningEvents->pluck('type')->last())->toBe('resolved');
});

it('FR-R9 a manual retry charges now, whatever the schedule says', function () {
    $invoice = openInvoice();
    failFirstPayment($invoice);
    fakeStripe()->declineInvoice('in_open1', 'insufficient_funds', 'pi_manual');

    $attempt = dunning()->retry($invoice->fresh(), manual: true);

    expect($attempt->kind)->toBe('manual')->and($attempt->attempt_number)->toBe(2)->and(fakeStripe()->callsTo('payInvoice'))->toHaveCount(1);
});

it('FR-R7 the retry command queues one job per due invoice', function () {
    Queue::fake();
    $due = openInvoice();
    failFirstPayment($due);
    Carbon::setTestNow('2026-11-03 09:00:00');
    Invoice::factory()->create(['provider_id' => $due->provider_id, 'dunning_status' => 'retrying', 'next_retry_at' => now()->addDay()]);
    Invoice::factory()->create(['provider_id' => $due->provider_id, 'dunning_status' => 'awaiting_customer']);

    $this->artisan('billing:retry-payments')->assertSuccessful();

    Queue::assertPushed(RetryInvoicePayment::class, 1);
    Queue::assertPushed(RetryInvoicePayment::class, fn ($job) => $job->invoiceId === $due->id);
});

it('FR-R7 the queued job retries the invoice', function () {
    $invoice = openInvoice();
    failFirstPayment($invoice);
    Carbon::setTestNow('2026-11-03 09:00:00');

    dispatch_sync(new RetryInvoicePayment($invoice->id));

    expect($invoice->fresh()->dunning_status)->toBe('recovered');
});

it('FR-R5 the grace command applies the final action to expired grace', function () {
    $invoice = openInvoice();
    failFirstPayment($invoice, 'stolen_card');
    Carbon::setTestNow('2026-11-09 09:00:00');

    $this->artisan('billing:expire-grace')->assertSuccessful();

    expect($invoice->subscription->fresh()->status)->toBe(SubscriptionStatus::Canceled);
});

it('FR-R7 the schedule runs retries and grace expiry', function () {
    $commands = collect(app(Schedule::class)->events())->pluck('command')->implode(' ');

    expect($commands)->toContain('billing:retry-payments')->toContain('billing:expire-grace');
});
