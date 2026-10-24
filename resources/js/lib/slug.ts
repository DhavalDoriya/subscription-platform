/** Mirrors the backend slug rules (App\Support\Tenancy\SlugRules): 3 to 40 of a-z, 0-9 and single hyphens, not at either end. */
export const SLUG_PATTERN = /^[a-z0-9](?!.*--)[a-z0-9-]{1,38}[a-z0-9]$/;

export const isValidSlug = (slug: string) => SLUG_PATTERN.test(slug);

/** Makes typed text usable as an address as you go: lower case, spaces become hyphens, anything else is dropped. */
export const normalizeSlug = (input: string) =>
    input
        .toLowerCase()
        .replace(/\s+/g, '-')
        .replace(/[^a-z0-9-]/g, '')
        .slice(0, 40);
