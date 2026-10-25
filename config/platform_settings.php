<?php

/*
 * Every admin-editable platform setting is declared here: type, default, whether it is a secret,
 * and (optionally) extra validation rules. Keys are "<group>.<name>"; the group is what the
 * admin UI sections on. Values live in the `settings` table (see App\Services\Settings\SettingsService).
 *
 * type: bool | int | string | list (array of strings; `item_rules` validate each entry)
 * secret: stored encrypted, never returned by the API, changeable by super admins only
 */
return [
    'general.platform_name' => ['type' => 'string', 'default' => 'Billing Platform'],
    'general.support_email' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'email']],
    'general.default_currency' => ['type' => 'string', 'default' => 'usd', 'rules' => ['string', 'size:3']],

    'provider.auto_approve' => ['type' => 'bool', 'default' => true],
    'provider.reserved_slugs' => ['type' => 'list', 'default' => []],
    'provider.max_plans' => ['type' => 'int', 'default' => 10, 'rules' => ['integer', 'min:1', 'max:1000']],
    'provider.min_price' => ['type' => 'int', 'default' => 100, 'rules' => ['integer', 'min:1']],
    'provider.max_price' => ['type' => 'int', 'default' => 1000000, 'rules' => ['integer', 'min:1']],
    'provider.allowed_currencies' => ['type' => 'list', 'default' => ['usd', 'eur', 'gbp'], 'rules' => ['array', 'min:1']],
    'provider.allowed_intervals' => ['type' => 'list', 'default' => ['month', 'year']],

    'media.max_upload_kb' => ['type' => 'int', 'default' => 5120, 'rules' => ['integer', 'min:100', 'max:20480']],
    'media.max_width' => ['type' => 'int', 'default' => 2000, 'rules' => ['integer', 'min:320', 'max:8000']],

    // FR-R2/R5: our own retry schedule (days after the first failure), then grace, then the final action.
    'dunning.retry_days' => ['type' => 'list', 'default' => ['1', '3', '5', '7'], 'rules' => ['array', 'min:1', 'max:10'], 'item_rules' => ['integer', 'min:1', 'max:60']],
    'dunning.grace_days' => ['type' => 'int', 'default' => 7, 'rules' => ['integer', 'min:0', 'max:60']],
    'dunning.final_action' => ['type' => 'string', 'default' => 'cancel', 'rules' => ['string', 'in:cancel,pause']],

    'stripe.secret_key' => ['type' => 'string', 'default' => null, 'secret' => true],
    'stripe.webhook_secret' => ['type' => 'string', 'default' => null, 'secret' => true],
    'stripe.connect_webhook_secret' => ['type' => 'string', 'default' => null, 'secret' => true],
    'stripe.publishable_key' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string', 'starts_with:pk_']],
    'stripe.tax_enabled' => ['type' => 'bool', 'default' => false],

    // FR-E5: how the platform sends email. Driver `default` = the server's own MAIL_* configuration.
    'mail.driver' => ['type' => 'string', 'default' => 'default', 'rules' => ['string', 'in:default,smtp,ses,mailgun']],
    'mail.host' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string', 'max:255']],
    'mail.port' => ['type' => 'int', 'default' => null, 'rules' => ['nullable', 'integer', 'min:1', 'max:65535']],
    'mail.username' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string', 'max:255']],
    'mail.password' => ['type' => 'string', 'default' => null, 'secret' => true],
    'mail.encryption' => ['type' => 'string', 'default' => 'tls', 'rules' => ['string', 'in:none,tls,ssl']],
    'mail.api_key' => ['type' => 'string', 'default' => null, 'secret' => true],
    'mail.api_domain' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string', 'max:255']],
    'mail.from_address' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'email']],
    'mail.from_name' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string', 'max:255']],

    'security.min_password_length' => ['type' => 'int', 'default' => 8, 'rules' => ['integer', 'min:8', 'max:128']],
];
