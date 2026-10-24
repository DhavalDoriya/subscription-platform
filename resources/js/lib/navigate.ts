/** Full-page navigation to another site (Stripe). Kept in one place so tests can replace it; in-app moves use the router. */
export const redirectTo = (url: string): void => window.location.assign(url);
