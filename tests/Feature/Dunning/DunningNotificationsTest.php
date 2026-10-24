<?php

use App\Models\Invoice;
use App\Services\Dunning\DunningService;
use App\Services\Mail\TemplateMail;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow('2026-11-02 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

function sentMail(): array
{
    return app(TemplateMail::class)->sent;
}

function sentTemplates(): array
{
    return array_column(sentMail(), 'template');
}

it('FR-R6 a soft decline sends the payment-failed notice with what the customer needs', function () {
    $invoice = openInvoice();

    app(DunningService::class)->recordFailure($invoice, 'insufficient_funds', 'Your card has insufficient funds.', 'pi_1');

    expect(sentTemplates())->toBe(['dunning.payment_failed']);
    $mail = sentMail()[0];
    $customer = $invoice->providerCustomer->user;
    expect($mail['to'])->toBe($customer->email)
        ->and($mail['provider_id'])->toBe($invoice->provider_id)
        ->and($mail['variables'])->toMatchArray([
            'customer.name' => $customer->name,
            'customer.email' => $customer->email,
            'provider.name' => $invoice->provider->name,
            'plan.name' => $invoice->subscription->plan->name,
            'amount' => '20.00 USD',
            'retry_date' => '2026-11-03',
            'decline_reason' => 'Your card has insufficient funds.',
        ])
        ->and($mail['variables']['update_payment_url'])->toEndWith('/account/fix-payment?subscription='.$invoice->subscription_id);
});

it('FR-R6 a hard decline asks for a new card instead of announcing a retry', function () {
    $invoice = openInvoice();

    app(DunningService::class)->recordFailure($invoice, 'stolen_card', 'Declined.', 'pi_1');

    expect(sentTemplates())->toBe(['dunning.action_required', 'dunning.final_notice']);
    expect(sentMail()[1]['variables']['grace_ends_at'])->toBe('2026-11-09');
});

it('FR-R6 authentication required sends the action-required notice', function () {
    $invoice = openInvoice();

    app(DunningService::class)->recordFailure($invoice, 'authentication_required', 'Needs 3DS.', 'pi_1');

    expect(sentTemplates())->toContain('dunning.action_required');
});

it('FR-R6 later retry failures remind the customer of the next try', function () {
    $invoice = openInvoice();
    app(DunningService::class)->recordFailure($invoice, 'insufficient_funds', null, 'pi_1');
    fakeStripe()->declineInvoice('in_open1', 'insufficient_funds', 'pi_2');
    Carbon::setTestNow('2026-11-03 09:00:00');
    app(DunningService::class)->retry($invoice->fresh());

    expect(sentTemplates())->toBe(['dunning.payment_failed', 'dunning.retry_reminder']);
    expect(sentMail()[1]['variables']['retry_date'])->toBe('2026-11-05');
});

it('FR-R6 recovery has its notice', function () {
    $invoice = openInvoice();
    app(DunningService::class)->recordFailure($invoice, 'insufficient_funds', null, 'pi_1');
    Carbon::setTestNow('2026-11-03 09:00:00');

    app(DunningService::class)->retry($invoice->fresh());

    expect(sentTemplates())->toContain('dunning.payment_recovered');
});

it('FR-R6 cancellation after grace has its notice', function () {
    $invoice = openInvoice();
    app(DunningService::class)->recordFailure($invoice, 'stolen_card', null, 'pi_9');

    Carbon::setTestNow('2026-11-20 09:00:00');
    app(DunningService::class)->expireGrace();

    expect(sentTemplates())->toContain('dunning.subscription_canceled');
});

it('FR-R6 a pause after grace has its notice', function () {
    app(SettingsService::class)->set('dunning.final_action', 'pause');
    $invoice = openInvoice();
    app(DunningService::class)->recordFailure($invoice, 'stolen_card', null, 'pi_9');

    Carbon::setTestNow('2026-11-20 09:00:00');
    app(DunningService::class)->expireGrace();

    expect(sentTemplates())->toContain('dunning.subscription_suspended');
});

it('FR-R6 the notice is written on the dunning timeline and the mail never blocks dunning', function () {
    app()->instance(TemplateMail::class, new class implements TemplateMail
    {
        public function send(string $template, string $to, array $variables, ?string $providerId = null): void
        {
            throw new RuntimeException('SMTP down');
        }
    });
    $invoice = openInvoice();

    app(DunningService::class)->recordFailure($invoice, 'insufficient_funds', null, 'pi_1');

    expect(Invoice::find($invoice->id)->dunning_status)->toBe('retrying')
        ->and($invoice->dunningEvents()->where('type', 'failure_notice')->first()->payload['mail_error'] ?? null)->toBe('SMTP down');
});

it('FR-R6 the stub mailer records the template without sending anything', function () {
    $invoice = openInvoice();

    app(DunningService::class)->recordFailure($invoice, 'insufficient_funds', null, 'pi_1');

    expect($invoice->dunningEvents()->where('type', 'failure_notice')->first()->payload['template'])->toBe('dunning.payment_failed');
});
