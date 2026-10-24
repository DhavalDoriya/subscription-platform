<?php

/*
 * Default email template set (PRD 4.9, FR-E3) and the documented placeholder list.
 *
 * 'variables': every placeholder an admin may use, with a description and the sample value used by preview and test-send.
 * 'templates': key => name, description, variables (which of the above the sender passes), subject, body (paragraphs)
 *              and an optional cta [label, variable holding the link]. Defaults are copied into the `email_templates` table
 *              once (php artisan db:seed --class=EmailTemplateSeeder, or on first use); after that the database copy rules.
 */
return [
    'variables' => [
        'platform.name' => ['The platform name', 'Billing Platform'],
        'user.name' => ['Name of the person receiving the email', 'Ada Lovelace'],
        'user.email' => ['Email address of the recipient', 'ada@example.com'],
        'customer.name' => ['Customer name', 'Ada Lovelace'],
        'customer.email' => ['Customer email address', 'ada@example.com'],
        'provider.name' => ['Name of the provider (brand) the customer subscribed to', 'Acme Fitness'],
        'plan.name' => ['Plan name', 'Gold plan'],
        'old_plan.name' => ['Plan the customer changed from', 'Silver plan'],
        'amount' => ['Amount with currency', '20.00 USD'],
        'paid_at' => ['Date the payment was received', '2026-11-02'],
        'next_billing_date' => ['Date of the next charge', '2026-12-02'],
        'renewal_date' => ['Date the subscription renews', '2026-12-02'],
        'trial_ends_at' => ['Date the free trial ends', '2026-11-09'],
        'ends_at' => ['Date access ends', '2026-12-02'],
        'retry_date' => ['Date of the next payment attempt', '2026-11-05'],
        'grace_ends_at' => ['Date the grace period ends', '2026-11-09'],
        'decline_reason' => ['Why the card was declined, as reported by the bank', 'Your card has insufficient funds.'],
        'card_last4' => ['Last four digits of the card', '4242'],
        'card_expiry' => ['Card expiry month and year', '11/2026'],
        'update_payment_url' => ['Link where the customer fixes their payment method', 'https://example.com/account/fix-payment'],
        'invoice_url' => ['Link to the invoice or receipt', 'https://example.com/account/invoices/in_123'],
        'login_url' => ['Link to sign in', 'https://example.com/login'],
        'verify_url' => ['Link that verifies the email address', 'https://example.com/verify-email?token=abc'],
        'reset_url' => ['Link that resets the password', 'https://example.com/reset-password?token=abc'],
        'expires_in' => ['How long the link stays valid', '60 minutes'],
        'dashboard_url' => ['Link to the provider dashboard', 'https://example.com/dashboard'],
        'onboarding_url' => ['Link to continue Stripe onboarding', 'https://example.com/dashboard/stripe'],
        'reason' => ['Reason given by the admin', 'Incomplete business information.'],
        'admin_url' => ['Link to the relevant admin page', 'https://example.com/admin/providers'],
        'count' => ['Number of items affected', '5'],
        'rate' => ['Failure rate in percent', '12'],
        'period' => ['Time window the numbers cover', 'the last hour'],
    ],

    'templates' => [
        // Auth
        'auth.welcome' => [
            'name' => 'Welcome', 'description' => 'Sent after sign-up.',
            'variables' => ['user.name', 'platform.name', 'login_url'],
            'subject' => 'Welcome to {{platform.name}}',
            'body' => ['Hi {{user.name}},', 'Your account is ready. Welcome to {{platform.name}}.'],
            'cta' => ['Sign in', 'login_url'],
        ],
        'auth.verify_email' => [
            'name' => 'Verify email', 'description' => 'Asks the user to confirm their email address.',
            'variables' => ['user.name', 'platform.name', 'verify_url', 'expires_in'],
            'subject' => 'Verify your email address',
            'body' => ['Hi {{user.name}},', 'Please confirm your email address. The link is valid for {{expires_in}}.'],
            'cta' => ['Verify email', 'verify_url'],
        ],
        'auth.password_reset' => [
            'name' => 'Password reset', 'description' => 'Sent when a user asks to reset their password.',
            'variables' => ['user.name', 'platform.name', 'reset_url', 'expires_in'],
            'subject' => 'Reset your password',
            'body' => ['Hi {{user.name}},', 'We received a request to reset your password. The link is valid for {{expires_in}}. If this was not you, ignore this email.'],
            'cta' => ['Reset password', 'reset_url'],
        ],
        'auth.password_changed' => [
            'name' => 'Password changed', 'description' => 'Confirms a password change.',
            'variables' => ['user.name', 'platform.name'],
            'subject' => 'Your password was changed',
            'body' => ['Hi {{user.name}},', 'The password of your {{platform.name}} account was just changed. If this was not you, reset it right away and contact support.'],
        ],
        'auth.two_factor_enabled' => [
            'name' => 'Two-factor enabled', 'description' => 'Confirms two-factor authentication was turned on.',
            'variables' => ['user.name', 'platform.name'],
            'subject' => 'Two-factor authentication is on',
            'body' => ['Hi {{user.name}},', 'Two-factor authentication is now enabled on your {{platform.name}} account.'],
        ],

        // Provider
        'provider.registration_received' => [
            'name' => 'Provider registration received', 'description' => 'Sent to a new provider while they wait for approval.',
            'variables' => ['user.name', 'provider.name', 'platform.name'],
            'subject' => 'We received your registration for {{provider.name}}',
            'body' => ['Hi {{user.name}},', 'Thanks for registering {{provider.name}} on {{platform.name}}. We will review it shortly.'],
        ],
        'provider.approved' => [
            'name' => 'Provider approved', 'description' => 'Sent when an admin approves a provider.',
            'variables' => ['user.name', 'provider.name', 'dashboard_url'],
            'subject' => '{{provider.name}} is approved',
            'body' => ['Hi {{user.name}},', '{{provider.name}} has been approved. You can now set up your plans and storefront.'],
            'cta' => ['Open dashboard', 'dashboard_url'],
        ],
        'provider.rejected' => [
            'name' => 'Provider rejected', 'description' => 'Sent when an admin rejects a provider.',
            'variables' => ['user.name', 'provider.name', 'reason'],
            'subject' => 'Your registration for {{provider.name}}',
            'body' => ['Hi {{user.name}},', 'We could not approve {{provider.name}}.', 'Reason: {{reason}}'],
        ],
        'provider.suspended' => [
            'name' => 'Provider suspended', 'description' => 'Sent when a provider is suspended.',
            'variables' => ['user.name', 'provider.name', 'reason'],
            'subject' => '{{provider.name}} has been suspended',
            'body' => ['Hi {{user.name}},', '{{provider.name}} is suspended: billing is paused and the storefront is unavailable.', 'Reason: {{reason}}'],
        ],
        'provider.reactivated' => [
            'name' => 'Provider reactivated', 'description' => 'Sent when a suspended provider is reactivated.',
            'variables' => ['user.name', 'provider.name', 'dashboard_url'],
            'subject' => '{{provider.name}} is active again',
            'body' => ['Hi {{user.name}},', '{{provider.name}} has been reactivated. Billing and the storefront are back.'],
            'cta' => ['Open dashboard', 'dashboard_url'],
        ],
        'provider.stripe_onboarding_reminder' => [
            'name' => 'Stripe onboarding reminder', 'description' => 'Nudges a provider to finish Stripe onboarding.',
            'variables' => ['user.name', 'provider.name', 'onboarding_url'],
            'subject' => 'Finish connecting Stripe for {{provider.name}}',
            'body' => ['Hi {{user.name}},', 'To accept payments for {{provider.name}} you need to finish connecting your Stripe account.'],
            'cta' => ['Continue onboarding', 'onboarding_url'],
        ],
        'provider.new_subscriber' => [
            'name' => 'New subscriber', 'description' => 'Tells a provider about a new subscriber.',
            'variables' => ['user.name', 'provider.name', 'customer.name', 'customer.email', 'plan.name', 'amount'],
            'subject' => 'New subscriber: {{customer.name}}',
            'body' => ['Hi {{user.name}},', '{{customer.name}} ({{customer.email}}) subscribed to {{plan.name}} on {{provider.name}} for {{amount}}.'],
        ],
        'provider.plan_canceled' => [
            'name' => 'Plan canceled by customer', 'description' => 'Tells a provider a customer canceled.',
            'variables' => ['user.name', 'provider.name', 'customer.name', 'plan.name', 'ends_at'],
            'subject' => '{{customer.name}} canceled {{plan.name}}',
            'body' => ['Hi {{user.name}},', '{{customer.name}} canceled {{plan.name}} on {{provider.name}}. Access ends on {{ends_at}}.'],
        ],

        // Customer billing
        'customer.subscription_started' => [
            'name' => 'Subscription started', 'description' => 'Confirms a new subscription.',
            'variables' => ['customer.name', 'provider.name', 'plan.name', 'amount', 'next_billing_date'],
            'subject' => 'Welcome to {{plan.name}}',
            'body' => ['Hi {{customer.name}},', 'Your {{plan.name}} subscription with {{provider.name}} is active. You will be charged {{amount}}; the next charge is on {{next_billing_date}}.'],
        ],
        'customer.trial_ending' => [
            'name' => 'Trial ending', 'description' => 'Warns that a free trial is about to end.',
            'variables' => ['customer.name', 'provider.name', 'plan.name', 'amount', 'trial_ends_at'],
            'subject' => 'Your {{plan.name}} trial ends on {{trial_ends_at}}',
            'body' => ['Hi {{customer.name}},', 'Your free trial of {{plan.name}} with {{provider.name}} ends on {{trial_ends_at}}. After that you will be charged {{amount}}.'],
        ],
        'customer.receipt' => [
            'name' => 'Receipt', 'description' => 'Receipt for a paid invoice.',
            'variables' => ['customer.name', 'provider.name', 'plan.name', 'amount', 'paid_at', 'invoice_url'],
            'subject' => 'Receipt from {{provider.name}}',
            'body' => ['Hi {{customer.name}},', 'We received your payment of {{amount}} for {{plan.name}} on {{paid_at}}. Thank you.'],
            'cta' => ['View invoice', 'invoice_url'],
        ],
        'customer.upcoming_renewal' => [
            'name' => 'Upcoming renewal', 'description' => 'Reminds the customer of an upcoming charge.',
            'variables' => ['customer.name', 'provider.name', 'plan.name', 'amount', 'renewal_date'],
            'subject' => 'Your {{plan.name}} renews on {{renewal_date}}',
            'body' => ['Hi {{customer.name}},', 'Your {{plan.name}} subscription with {{provider.name}} renews on {{renewal_date}} for {{amount}}.'],
        ],
        'customer.subscription_canceled' => [
            'name' => 'Subscription canceled', 'description' => 'Confirms a cancellation.',
            'variables' => ['customer.name', 'provider.name', 'plan.name', 'ends_at'],
            'subject' => 'Your {{plan.name}} subscription is canceled',
            'body' => ['Hi {{customer.name}},', 'Your {{plan.name}} subscription with {{provider.name}} is canceled. You keep access until {{ends_at}}.'],
        ],
        'customer.subscription_resumed' => [
            'name' => 'Subscription resumed', 'description' => 'Confirms a canceled subscription was resumed.',
            'variables' => ['customer.name', 'provider.name', 'plan.name', 'next_billing_date'],
            'subject' => 'Your {{plan.name}} subscription continues',
            'body' => ['Hi {{customer.name}},', 'Your {{plan.name}} subscription with {{provider.name}} will continue. The next charge is on {{next_billing_date}}.'],
        ],
        'customer.plan_changed' => [
            'name' => 'Plan changed', 'description' => 'Confirms an upgrade or downgrade.',
            'variables' => ['customer.name', 'provider.name', 'old_plan.name', 'plan.name', 'amount'],
            'subject' => 'You changed to {{plan.name}}',
            'body' => ['Hi {{customer.name}},', 'Your subscription with {{provider.name}} changed from {{old_plan.name}} to {{plan.name}} ({{amount}}).'],
        ],
        'customer.refund_issued' => [
            'name' => 'Refund issued', 'description' => 'Confirms a refund.',
            'variables' => ['customer.name', 'provider.name', 'amount', 'invoice_url'],
            'subject' => 'Your refund from {{provider.name}}',
            'body' => ['Hi {{customer.name}},', '{{provider.name}} refunded {{amount}}. It can take a few days to show on your statement.'],
            'cta' => ['View invoice', 'invoice_url'],
        ],

        // Dunning (sent by App\Services\Dunning\DunningNotifier)
        'dunning.payment_failed' => [
            'name' => 'Payment failed', 'description' => 'First notice after a renewal payment fails.',
            'variables' => ['customer.name', 'customer.email', 'provider.name', 'plan.name', 'amount', 'retry_date', 'decline_reason', 'update_payment_url'],
            'subject' => 'Your payment to {{provider.name}} failed',
            'body' => ['Hi {{customer.name}},', 'We could not collect {{amount}} for {{plan.name}} from {{provider.name}}. {{decline_reason}}', 'We will try again on {{retry_date}}. You can update your payment method at any time.'],
            'cta' => ['Update payment method', 'update_payment_url'],
        ],
        'dunning.retry_reminder' => [
            'name' => 'Retry reminder', 'description' => 'Reminder before another payment attempt.',
            'variables' => ['customer.name', 'customer.email', 'provider.name', 'plan.name', 'amount', 'retry_date', 'update_payment_url'],
            'subject' => 'We will retry your {{provider.name}} payment on {{retry_date}}',
            'body' => ['Hi {{customer.name}},', 'Your payment of {{amount}} for {{plan.name}} is still unpaid. We will try again on {{retry_date}}.'],
            'cta' => ['Update payment method', 'update_payment_url'],
        ],
        'dunning.action_required' => [
            'name' => 'Action required', 'description' => 'The card needs to be replaced or the payment confirmed (3-D Secure).',
            'variables' => ['customer.name', 'customer.email', 'provider.name', 'plan.name', 'amount', 'decline_reason', 'update_payment_url'],
            'subject' => 'Action needed to keep your {{provider.name}} subscription',
            'body' => ['Hi {{customer.name}},', 'Your payment of {{amount}} for {{plan.name}} needs your attention. {{decline_reason}}', 'Please update or confirm your payment method.'],
            'cta' => ['Fix payment', 'update_payment_url'],
        ],
        'dunning.final_notice' => [
            'name' => 'Final notice', 'description' => 'Retries are used up; access ends when the grace period ends.',
            'variables' => ['customer.name', 'customer.email', 'provider.name', 'plan.name', 'amount', 'grace_ends_at', 'update_payment_url'],
            'subject' => 'Final notice: your {{provider.name}} subscription will end',
            'body' => ['Hi {{customer.name}},', 'We could not collect {{amount}} for {{plan.name}}. Unless the payment is fixed by {{grace_ends_at}}, your subscription will end.'],
            'cta' => ['Update payment method', 'update_payment_url'],
        ],
        'dunning.subscription_canceled' => [
            'name' => 'Subscription canceled (unpaid)', 'description' => 'The subscription was canceled after payment failed.',
            'variables' => ['customer.name', 'customer.email', 'provider.name', 'plan.name', 'amount'],
            'subject' => 'Your {{provider.name}} subscription was canceled',
            'body' => ['Hi {{customer.name}},', 'Because we could not collect {{amount}}, your {{plan.name}} subscription with {{provider.name}} was canceled.'],
        ],
        'dunning.subscription_suspended' => [
            'name' => 'Subscription suspended', 'description' => 'The subscription was paused after payment failed.',
            'variables' => ['customer.name', 'customer.email', 'provider.name', 'plan.name', 'amount', 'update_payment_url'],
            'subject' => 'Your {{provider.name}} subscription is paused',
            'body' => ['Hi {{customer.name}},', 'Because we could not collect {{amount}}, your {{plan.name}} subscription is paused. Update your payment method to continue.'],
            'cta' => ['Update payment method', 'update_payment_url'],
        ],
        'dunning.payment_recovered' => [
            'name' => 'Payment recovered', 'description' => 'The overdue payment finally succeeded.',
            'variables' => ['customer.name', 'customer.email', 'provider.name', 'plan.name', 'amount'],
            'subject' => 'Payment received, thank you',
            'body' => ['Hi {{customer.name}},', 'We collected {{amount}} for {{plan.name}} with {{provider.name}}. Your subscription is in good standing again.'],
        ],
        'dunning.card_expiring' => [
            'name' => 'Card expiring', 'description' => 'Warns that the card on file expires soon.',
            'variables' => ['customer.name', 'provider.name', 'card_last4', 'card_expiry', 'update_payment_url'],
            'subject' => 'Your card ending {{card_last4}} expires soon',
            'body' => ['Hi {{customer.name}},', 'The card ending {{card_last4}} you use for {{provider.name}} expires on {{card_expiry}}. Update it to avoid a failed payment.'],
            'cta' => ['Update card', 'update_payment_url'],
        ],

        // Admin alerts
        'admin.provider_pending' => [
            'name' => 'Provider pending approval', 'description' => 'Tells admins a provider waits for approval.',
            'variables' => ['provider.name', 'user.name', 'admin_url'],
            'subject' => 'New provider waiting: {{provider.name}}',
            'body' => ['{{provider.name}} (owner {{user.name}}) registered and waits for approval.'],
            'cta' => ['Review', 'admin_url'],
        ],
        'admin.webhook_failures' => [
            'name' => 'Webhook failures', 'description' => 'Stripe webhook events failed to process.',
            'variables' => ['count', 'period', 'admin_url'],
            'subject' => '{{count}} Stripe webhook events failed',
            'body' => ['{{count}} Stripe webhook events failed in {{period}}. Replay them once the cause is fixed.'],
            'cta' => ['Open webhooks', 'admin_url'],
        ],
        'admin.high_failure_rate' => [
            'name' => 'High payment failure rate', 'description' => 'The share of failed payments is unusually high.',
            'variables' => ['rate', 'period', 'admin_url'],
            'subject' => 'Payment failure rate is {{rate}}%',
            'body' => ['{{rate}}% of payments failed in {{period}}.'],
            'cta' => ['Open invoices', 'admin_url'],
        ],
    ],
];
