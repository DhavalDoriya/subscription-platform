<?php

namespace App\Services\Mail;

/**
 * Sends an email built from an admin-editable template (FR-E1) such as `dunning.payment_failed`. Until the template
 * system of Phase 7 exists the container binds StubTemplateMail, so callers can already be written against this.
 */
interface TemplateMail
{
    /** @param  array<string, mixed>  $variables  keyed like the placeholders, e.g. `customer.name`, `amount`, `update_payment_url` */
    public function send(string $template, string $to, array $variables, ?string $providerId = null): void;
}
