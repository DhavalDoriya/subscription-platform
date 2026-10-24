<?php

namespace App\Services\Dunning;

use App\Models\Invoice;
use App\Services\Mail\TemplateMail;
use Throwable;

/**
 * Turns dunning events into customer emails (FR-R6) by template key. Which events mail whom is decided here; wording and
 * delivery belong to the template system. A mail failure is reported back and never stops the dunning itself.
 */
class DunningNotifier
{
    /** event type => template key; failure_notice and retry_scheduled have their own rules in templateFor(). */
    private const TEMPLATES = [
        'new_card_requested' => 'dunning.action_required',
        'auth_required' => 'dunning.action_required',
        'grace_started' => 'dunning.final_notice',
        'canceled' => 'dunning.subscription_canceled',
        'paused' => 'dunning.subscription_suspended',
        'recovered' => 'dunning.payment_recovered',
    ];

    public function __construct(private TemplateMail $mail) {}

    /**
     * @param  array<string, mixed>  $payload  the event payload
     * @return array<string, mixed> what to add to the event payload: the template used, or the mail error
     */
    public function notify(Invoice $invoice, string $event, array $payload): array
    {
        $template = $this->templateFor($event, $payload);
        $email = $invoice->providerCustomer?->user?->email;

        if (! $template || ! $email) {
            return [];
        }

        try {
            $this->mail->send($template, $email, $this->variables($invoice), $invoice->provider_id);

            return ['template' => $template];
        } catch (Throwable $e) {
            report($e);

            return ['template' => $template, 'mail_error' => $e->getMessage()];
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function templateFor(string $event, array $payload): ?string
    {
        return match ($event) {
            // A hard or 3DS decline is announced by its own "action required" notice instead.
            'failure_notice' => ($payload['decline_type'] ?? 'soft') === 'soft' ? 'dunning.payment_failed' : null,
            // The first scheduled retry is already in the failure notice; later ones are reminders.
            'retry_scheduled' => ($payload['attempt'] ?? 0) >= 3 ? 'dunning.retry_reminder' : null,
            default => self::TEMPLATES[$event] ?? null,
        };
    }

    /** @return array<string, mixed> */
    private function variables(Invoice $invoice): array
    {
        $invoice->loadMissing(['provider', 'providerCustomer.user', 'subscription.plan']);
        $user = $invoice->providerCustomer->user;
        $last = $invoice->attempts()->reorder('attempt_number', 'desc')->first();

        return [
            'customer.name' => $user->name,
            'customer.email' => $user->email,
            'provider.name' => $invoice->provider->name,
            'plan.name' => $invoice->subscription?->plan?->name,
            'amount' => number_format($invoice->amount_due / 100, 2, '.', '').' '.strtoupper($invoice->currency),
            'retry_date' => $invoice->next_retry_at?->toDateString(),
            'grace_ends_at' => $invoice->subscription?->grace_ends_at?->toDateString(),
            'decline_reason' => $last?->failure_message,
            'update_payment_url' => rtrim((string) config('app.url'), '/').'/account/fix-payment?subscription='.$invoice->subscription_id,
        ];
    }
}
