import { storefrontSlug } from './tenant';

describe('storefrontSlug', () => {
    it('returns the provider slug for a subdomain of the base domain', () => {
        expect(storefrontSlug('acme.platform.test', 'platform.test')).toBe('acme');
        expect(storefrontSlug('a-b-1.platform.test', 'platform.test')).toBe('a-b-1');
    });

    it('treats the bare domain, www and other reserved names as the main app', () => {
        for (const host of ['platform.test', 'www.platform.test', 'admin.platform.test', 'api.platform.test', 'app.platform.test']) {
            expect(storefrontSlug(host, 'platform.test')).toBeNull();
        }
    });

    it('ignores nested subdomains, foreign hosts and a missing base domain', () => {
        expect(storefrontSlug('a.b.platform.test', 'platform.test')).toBeNull();
        expect(storefrontSlug('acme.evil.test', 'platform.test')).toBeNull();
        expect(storefrontSlug('localhost', 'platform.test')).toBeNull();
        expect(storefrontSlug('acme.platform.test', undefined)).toBeNull();
    });

    it('is case-insensitive about the host', () => {
        expect(storefrontSlug('ACME.Platform.Test', 'platform.test')).toBe('acme');
    });
});
