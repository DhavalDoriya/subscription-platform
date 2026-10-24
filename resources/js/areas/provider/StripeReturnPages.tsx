import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { Alert } from '@/components/ui/Alert';
import { ApiError } from '@/lib/api';
import { redirectTo } from '@/lib/navigate';
import { refreshStripeStatus, useStripeActions } from './services/stripe';

function Failure({ message }: { message: string }) {
    return (
        <main className="mx-auto max-w-md px-4 py-16">
            <Alert>{message}</Alert>
            <Link to="/dashboard" className="mt-4 inline-block text-sm text-primary hover:underline">
                Back to dashboard
            </Link>
        </main>
    );
}

const text = (e: unknown) => (e instanceof ApiError ? e.message : 'Something went wrong.');

/** Stripe sends the provider here when they finish (or leave) onboarding: pull the real status, then show the dashboard. */
export function StripeReturnPage() {
    const navigate = useNavigate();
    const client = useQueryClient();
    const [error, setError] = useState<string>();

    useEffect(() => {
        refreshStripeStatus()
            .then(() => client.invalidateQueries({ queryKey: ['provider'] }))
            .then(() => navigate('/dashboard', { replace: true }))
            .catch((e) => setError(text(e)));
    }, [client, navigate]);

    return error ? (
        <Failure message={error} />
    ) : (
        <p role="status" className="p-8 text-sm text-muted-foreground">
            Checking your Stripe account…
        </p>
    );
}

/** Stripe onboarding links expire; this makes a new one and sends the provider straight back. */
export function StripeRefreshPage() {
    const { onboarding } = useStripeActions();
    const [error, setError] = useState<string>();

    useEffect(() => {
        onboarding
            .mutateAsync()
            .then(({ url }) => redirectTo(url))
            .catch((e) => setError(text(e)));
        // Runs once on arrival; mutateAsync identity is stable enough and re-running would create extra account links.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return error ? (
        <Failure message={error} />
    ) : (
        <p role="status" className="p-8 text-sm text-muted-foreground">
            Taking you back to Stripe…
        </p>
    );
}
