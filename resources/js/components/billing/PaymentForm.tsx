import { Elements, PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js';
import { useMemo, useState, type FormEvent } from 'react';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { stripeFor } from '@/lib/stripe';

interface Props {
    publishableKey: string;
    stripeAccount: string;
    clientSecret: string;
    /** `payment` pays an invoice now; `setup` only saves a card (free trial, or adding a card later). */
    type: 'payment' | 'setup';
    returnUrl: string;
    submitLabel: string;
    onSuccess: () => void;
}

function Form({ type, returnUrl, submitLabel, onSuccess }: Pick<Props, 'type' | 'returnUrl' | 'submitLabel' | 'onSuccess'>) {
    const stripe = useStripe();
    const elements = useElements();
    const [error, setError] = useState<string>();
    const [pending, setPending] = useState(false);

    async function submit(event: FormEvent) {
        event.preventDefault();
        if (!stripe || !elements) return;

        setPending(true);
        setError(undefined);

        const args = { elements, confirmParams: { return_url: returnUrl }, redirect: 'if_required' as const };
        const result = type === 'payment' ? await stripe.confirmPayment(args) : await stripe.confirmSetup(args);

        setPending(false);

        if (result.error) setError(result.error.message ?? 'The payment could not be completed.');
        else onSuccess();
    }

    return (
        <form onSubmit={submit} className="grid gap-4">
            <PaymentElement />
            {error && <Alert>{error}</Alert>}
            <Button size="lg" type="submit" loading={pending} disabled={!stripe}>
                {submitLabel}
            </Button>
        </form>
    );
}

/** Stripe's Payment Element for a provider's connected account: handles cards, wallets and 3-D Secure (FR-C1). */
export function PaymentForm({ publishableKey, stripeAccount, clientSecret, ...form }: Props) {
    const stripe = useMemo(() => stripeFor(publishableKey, stripeAccount), [publishableKey, stripeAccount]);

    return (
        <Elements stripe={stripe} options={{ clientSecret }}>
            <Form {...form} />
        </Elements>
    );
}
