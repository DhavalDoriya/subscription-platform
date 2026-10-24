export type FieldKind = 'text' | 'email' | 'number' | 'toggle' | 'list' | 'secret';

export interface SettingField {
    key: string;
    label: string;
    kind: FieldKind;
    hint?: string;
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
            { key: 'stripe.secret_key', label: 'Secret key', kind: 'secret' },
            { key: 'stripe.webhook_secret', label: 'Webhook secret', kind: 'secret' },
            { key: 'stripe.connect_webhook_secret', label: 'Connect webhook secret', kind: 'secret' },
            { key: 'stripe.tax_enabled', label: 'Enable Stripe Tax', kind: 'toggle' },
        ],
    },
    {
        title: 'Security',
        description: 'Sign-in rules.',
        fields: [{ key: 'security.min_password_length', label: 'Minimum password length', kind: 'number' }],
    },
];
