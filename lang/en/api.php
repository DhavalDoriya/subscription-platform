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
        'session_invalid' => 'Session is no longer valid. Please sign in again.',
        'invalid_verification_link' => 'Invalid verification link.',
        'email_verified' => 'Email verified.',
        'verification_sent' => 'Verification email sent.',
        'reset_link_sent' => 'If that email is registered, a reset link is on its way.',
        'password_updated' => 'Password updated. Please sign in.',
    ],
];
