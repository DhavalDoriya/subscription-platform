import { loadStripe, type Stripe } from '@stripe/stripe-js';

const cache = new Map<string, Promise<Stripe | null>>();

/**
 * Stripe.js for one provider. Payments live on the provider's connected account (direct charges), so Stripe.js has to be
 * created for that account or it cannot find the payment intent. Cached so each account loads once.
 */
export function stripeFor(publishableKey: string, stripeAccount: string): Promise<Stripe | null> {
    const key = `${publishableKey}:${stripeAccount}`;
    if (!cache.has(key)) cache.set(key, loadStripe(publishableKey, { stripeAccount }));

    return cache.get(key)!;
}
