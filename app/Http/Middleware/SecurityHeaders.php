<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hardening headers on every response, plus a Content-Security-Policy for the SPA shell (FR-A6). The only third party the
 * app talks to from the browser is Stripe (Stripe.js, its payment frames and its API). Inline scripts need the per-request nonce.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(24);
        $request->attributes->set('csp_nonce', $nonce);
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(self "https://js.stripe.com")');
        $response->headers->remove('X-Powered-By');

        if (config('security.csp') && ! $request->is('api/*', 'horizon/*')) {
            $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        }

        if (config('security.hsts') && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /** Uploaded images are served from the media disk's URL, which can be another origin (a CDN, or http locally). */
    private function mediaOrigin(): string
    {
        $parts = parse_url((string) config('filesystems.disks.public.url'));

        return isset($parts['scheme'], $parts['host']) ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : '';
    }

    private function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' https://js.stripe.com",
            "style-src 'self' 'unsafe-inline'", // component libraries set inline styles for positioning
            "img-src 'self' data: blob: https: {$this->mediaOrigin()}",
            "font-src 'self' data:",
            "connect-src 'self' https://api.stripe.com",
            'frame-src https://js.stripe.com https://hooks.stripe.com',
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
