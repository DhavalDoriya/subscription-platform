<?php

/*
 * Every admin-editable platform setting is declared here: type, default, whether it is a secret,
 * and (optionally) extra validation rules. Keys are "<group>.<name>"; the group is what the
 * admin UI sections on. Values live in the `settings` table (see App\Services\Settings\SettingsService).
 *
 * type: bool | int | string | list (array of strings)
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

    'stripe.secret_key' => ['type' => 'string', 'default' => null, 'secret' => true],
    'stripe.webhook_secret' => ['type' => 'string', 'default' => null, 'secret' => true],
    'stripe.connect_webhook_secret' => ['type' => 'string', 'default' => null, 'secret' => true],
    'stripe.tax_enabled' => ['type' => 'bool', 'default' => false],

    'security.min_password_length' => ['type' => 'int', 'default' => 8, 'rules' => ['integer', 'min:8', 'max:128']],
];
