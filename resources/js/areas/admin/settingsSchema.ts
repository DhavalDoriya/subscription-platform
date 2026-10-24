export type FieldKind = 'text' | 'email' | 'number' | 'toggle' | 'list' | 'secret' | 'select';

export interface SettingField {
    key: string;
    label: string;
    kind: FieldKind;
    hint?: string;
    options?: { value: string; label: string }[];
}

export interface SettingGroup {
    title: string;
    description: string;
    fields: SettingField[];
}

/** What the settings form shows. Keys and types must match config/platform_settings.php; the API rejects anything else. */
export const settingGroups: SettingGroup[] = [
    {
        title: 'General',
        description: 'How the platform presents itself.',
        fields: [
            { key: 'general.platform_name', label: 'Platform name', kind: 'text' },
            { key: 'general.support_email', label: 'Support email', kind: 'email' },
            {
                key: 'general.default_currency',
                label: 'Default currency',
                kind: 'text',
                hint: 'Three-letter code, e.g. usd. Used when a provider does not pick one.',
            },
        ],
    },
    {
        title: 'Providers',
        description: 'Rules for brands selling on the platform.',
        fields: [
            {
                key: 'provider.auto_approve',
                label: 'Auto-approve new providers',
                kind: 'toggle',
                hint: 'When off, new providers wait for you in the Pending list.',
            },
            { key: 'provider.max_plans', label: 'Maximum plans per provider', kind: 'number' },
            { key: 'provider.min_price', label: 'Minimum price (minor units)', kind: 'number', hint: 'In the smallest unit, e.g. cents: 100 means 1.00.' },
            { key: 'provider.max_price', label: 'Maximum price (minor units)', kind: 'number' },
            {
                key: 'provider.allowed_currencies',
                label: 'Enabled currencies',
                kind: 'list',
                hint: 'Comma separated, e.g. usd, eur, gbp. Providers choose from these.',
            },
            { key: 'provider.allowed_intervals', label: 'Allowed billing intervals', kind: 'list', hint: 'Comma separated: day, week, month, year.' },
            {
                key: 'provider.reserved_slugs',
                label: 'Reserved addresses',
                kind: 'list',
                hint: 'Extra subdomains providers cannot take, on top of the built-in ones.',
            },
        ],
    },
    {
        title: 'Stripe',
        description: 'Payments. Only super admins can replace keys; saved keys are never shown.',
        fields: [
            {
                key: 'stripe.publishable_key',
                label: 'Publishable key',
                kind: 'text',
                hint: 'Starts with pk_. Public: the storefront uses it to show the card form.',
            },
            { key: 'stripe.secret_key', label: 'Secret key', kind: 'secret' },
            { key: 'stripe.webhook_secret', label: 'Webhook secret', kind: 'secret' },
            { key: 'stripe.connect_webhook_secret', label: 'Connect webhook secret', kind: 'secret' },
            { key: 'stripe.tax_enabled', label: 'Enable Stripe Tax', kind: 'toggle' },
        ],
    },
    {
        title: 'Payment recovery',
        description: "What happens when a renewal payment fails. Stripe's own retries are switched off; the platform retries on this schedule.",
        fields: [
            {
                key: 'dunning.retry_days',
                label: 'Retry after (days)',
                kind: 'list',
                hint: 'Days after the first failure, comma separated, e.g. 1, 3, 5, 7. Only temporary declines are retried.',
            },
            {
                key: 'dunning.grace_days',
                label: 'Grace period (days)',
                kind: 'number',
                hint: 'How long customers keep access once retries are used up or a card is refused for good. 0 ends it at once.',
            },
            {
                key: 'dunning.final_action',
                label: 'When grace ends',
                kind: 'select',
                options: [
                    { value: 'cancel', label: 'Cancel the subscription' },
                    { value: 'pause', label: 'Pause the subscription' },
                ],
            },
        ],
    },
    {
        title: 'Commission',
        description: 'Platform fee taken from each subscription payment. Per-provider rules arrive in a later phase.',
        fields: [{ key: 'commission.default_percent', label: 'Default commission (%)', kind: 'number' }],
    },
    {
        title: 'Mail',
        description:
            'How the platform sends email. Leave the driver on default to use the server configuration. Only super admins can replace passwords and keys; saved ones are never shown.',
        fields: [
            {
                key: 'mail.driver',
                label: 'Mail driver',
                kind: 'select',
                options: [
                    { value: 'default', label: 'Server default' },
                    { value: 'smtp', label: 'SMTP' },
                    { value: 'ses', label: 'Amazon SES' },
                    { value: 'mailgun', label: 'Mailgun' },
                ],
            },
            { key: 'mail.host', label: 'SMTP host', kind: 'text' },
            { key: 'mail.port', label: 'SMTP port', kind: 'number' },
            { key: 'mail.username', label: 'Username', kind: 'text', hint: 'For Amazon SES this is the access key id.' },
            { key: 'mail.password', label: 'Password', kind: 'secret' },
            {
                key: 'mail.encryption',
                label: 'Encryption',
                kind: 'select',
                options: [
                    { value: 'tls', label: 'TLS (STARTTLS)' },
                    { value: 'ssl', label: 'SSL' },
                    { value: 'none', label: 'None' },
                ],
            },
            { key: 'mail.api_key', label: 'API key', kind: 'secret' },
            {
                key: 'mail.api_domain',
                label: 'API domain or region',
                kind: 'text',
                hint: 'Mailgun: the sending domain. Amazon SES: the region, e.g. us-east-1.',
            },
            { key: 'mail.from_address', label: 'From address', kind: 'email' },
            { key: 'mail.from_name', label: 'From name', kind: 'text' },
        ],
    },
    {
        title: 'Security',
        description: 'Sign-in rules.',
        fields: [{ key: 'security.min_password_length', label: 'Minimum password length', kind: 'number' }],
    },
];
