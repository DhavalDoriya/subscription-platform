/** Full-page navigation to another site (Stripe). Kept in one place so tests can replace it; in-app moves use the router. */
export const redirectTo = (url: string): void => window.location.assign(url);

/** Opens a link in a new tab (preview links). Also replaceable in tests. */
export const openInNewTab = (url: string): void => {
    window.open(url, '_blank', 'noopener,noreferrer');
};
