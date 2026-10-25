<?php

/*
 * The SPA is served from the same host as the API (every storefront subdomain serves the same app), so browsers never
 * make cross-origin API calls. Laravel's default would answer every origin with `*`; nothing is allowed here until
 * CORS_ALLOWED_ORIGINS lists origins (comma separated) for a separate front end.
 */
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'Accept-Language'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
