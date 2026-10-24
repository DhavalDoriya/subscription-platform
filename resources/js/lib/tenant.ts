declare global {
    interface Window {
        /** Injected by resources/views/spa.blade.php. */
        __APP__?: { baseDomain?: string };
    }
}

/** Mirrors App\Support\Tenancy\SlugRules::BUILT_IN_RESERVED. Admin-added reserved slugs are caught by the API (404). */
const RESERVED = [
    'www',
    'admin',
    'api',
    'app',
    'mail',
    'dashboard',
    'account',
    'static',
    'assets',
    'cdn',
    'docs',
    'help',
    'status',
    'support',
    'billing',
    'login',
    'register',
    'webhooks',
    'stripe',
    'platform',
    'root',
    'smtp',
    'ftp',
    'ns1',
    'ns2',
];

/** The provider slug if this host is `<slug>.<base domain>`, otherwise null (the main app). */
export function storefrontSlug(hostname: string, baseDomain: string | undefined): string | null {
    if (!baseDomain) return null;

    const host = hostname.toLowerCase();
    const suffix = `.${baseDomain.toLowerCase()}`;

    if (!host.endsWith(suffix)) return null;

    const label = host.slice(0, -suffix.length);

    return label === '' || label.includes('.') || RESERVED.includes(label) ? null : label;
}
