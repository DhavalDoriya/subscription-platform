<?php

return [
    // Content-Security-Policy for the SPA shell. Off in local development, where Vite's dev server needs inline scripts and websockets.
    'csp' => env('SECURITY_CSP', env('APP_ENV') !== 'local'),

    // Strict-Transport-Security over https; on in production only.
    'hsts' => env('SECURITY_HSTS', env('APP_ENV') === 'production'),
];
