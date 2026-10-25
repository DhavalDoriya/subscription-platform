<?php

/*
 * Every user-facing API message lives here (never hard-code English in PHP).
 * To add a language: copy this file to lang/<locale>/api.php, translate it, and add the
 * locale to APP_SUPPORTED_LOCALES. Clients choose it with the Accept-Language header.
 */
return [
    'errors' => [
        'bad_request' => 'Bad request.',
        'unauthenticated' => 'Unauthenticated.',
        'unauthorized' => 'This action is unauthorized.',
        'forbidden' => 'Forbidden.',
        'not_found' => 'Resource not found.',
        'method_not_allowed' => 'Method not allowed.',
        'conflict' => 'Conflict.',
        'session_expired' => 'Session expired.',
        'too_many_requests' => 'Too many requests.',
        'server_error' => 'Server error.',
        'service_unavailable' => 'Service unavailable.',
        'request_failed' => 'Request failed.',
    ],

    'auth' => [
        'invalid_credentials' => 'Invalid email or password.',
        'too_many_attempts' => 'Too many login attempts. Try again later.',
        'account_blocked' => 'This account has been blocked. Contact support.',
        'session_invalid' => 'Session is no longer valid. Please sign in again.',
        'invalid_verification_link' => 'Invalid verification link.',
        'email_verified' => 'Email verified.',
        'verification_sent' => 'Verification email sent.',
        'reset_link_sent' => 'If that email is registered, a reset link is on its way.',
        'password_updated' => 'Password updated. Please sign in.',
    ],

    'settings' => [
        'unknown_key' => 'This setting does not exist.',
    ],

    'providers' => [
        'already_registered' => 'You already have a provider account.',
        'slug_invalid' => 'Use 3 to 40 lowercase letters, numbers or single hyphens, not starting or ending with a hyphen.',
        'slug_reserved' => 'This address is reserved. Choose another.',
        'slug_taken' => 'This address is already taken.',
        'invalid_transition' => 'A provider cannot move from :from to :to.',
        'not_found' => 'You do not have a provider account yet.',
        'tax_unavailable' => 'Stripe Tax is not enabled on this platform.',
        'tenant_not_found' => 'No provider lives at this address.',
    ],

    'admin' => [
        'cannot_remove_self' => 'You cannot remove your own account.',
        'cannot_remove_super_admin' => 'Super admins cannot be removed here.',
        'cannot_impersonate' => 'This account cannot be impersonated.',
        'not_impersonating' => 'This session is not an impersonation.',
    ],

    'stripe' => [
        'not_configured' => 'Stripe is not configured yet.',
        'invalid_signature' => 'Invalid webhook signature.',
        'provider_not_approved' => 'Your account must be approved before you can connect Stripe.',
        'not_connected' => 'Connect Stripe and finish onboarding first.',
        'event_not_replayable' => 'Only failed or unhandled events can be replayed.',
        'replay_failed' => 'The event failed again: :error',
    ],

    'media' => [
        'unsupported' => 'Upload a JPG, PNG, WebP or GIF image.',
    ],

    'plans' => [
        'plan_limit_reached' => 'You have reached the maximum number of plans.',
        'stripe_not_enabled' => 'Finish connecting Stripe before publishing plans or adding prices to a live plan.',
        'provider_not_approved' => 'Your account must be approved and active to publish plans.',
        'plan_needs_price' => 'A live plan needs at least one active price.',
        'plan_already_active' => 'This plan is already live.',
        'plan_archived' => 'This plan is archived.',
        'plan_in_use' => 'Only drafts can be deleted. Archive this plan instead.',
        'price_archived' => 'This price is archived.',
    ],

    'commission' => [
        'rule_already_effective' => 'This rate has already taken effect. Add a newer rate instead of removing it.',
    ],

    'dunning' => [
        'not_retryable' => 'This invoice cannot be retried: it is paid, or its subscription has ended or is paused.',
        'not_resolvable' => 'This invoice has no failed payment to resolve.',
    ],

    'billing' => [
        'storefront_required' => 'Subscribe from the provider\'s own storefront.',
        'checkout_unavailable' => 'This provider is not accepting new subscribers right now.',
        'already_subscribed' => 'You already have a subscription to this plan.',
        'not_cancelable' => 'This subscription can no longer be cancelled.',
        'not_resumable' => 'Only a subscription set to end can be resumed.',
        'not_changeable' => 'This subscription cannot change plan right now.',
        'same_price' => 'You are already on this price.',
        'currency_mismatch' => 'The new price must use the same currency.',
        'refund_exceeds' => 'The refund is larger than what is left to refund.',
        'payment_method_in_use' => 'Add another card before removing this one, or cancel your subscription first.',
        'not_refundable' => 'Only a successful payment can be refunded.',
    ],

    'storefront' => [
        'storefront_empty' => 'Add at least one visible block before publishing.',
        'provider_not_approved' => 'Your account must be approved and active to publish your storefront.',
    ],

    'mail' => [
        'test_subject' => 'Test email from :platform',
        'test_body' => 'This is a test email. Your mail settings work.',
        'test_failed' => 'The test email could not be sent: :error',
        'not_resendable' => 'Only a failed email can be resent.',
        'unknown_variables' => 'These placeholders are not available in this template: :variables.',
    ],
];
