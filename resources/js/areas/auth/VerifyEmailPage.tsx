import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Alert } from '@/components/ui/Alert';
import { api } from '@/lib/api';
import { AuthLayout } from './AuthLayout';

/** The emailed link lands here; we forward its id, hash, expires and signature to the signed API route. */
export function VerifyEmailPage() {
    const [params] = useSearchParams();
    const [state, setState] = useState<'checking' | 'done' | 'failed'>('checking');

    useEffect(() => {
        const query = new URLSearchParams({ expires: params.get('expires') ?? '', signature: params.get('signature') ?? '' });
        api.get(`/auth/email/verify/${params.get('id')}/${params.get('hash')}?${query}`)
            .then(() => setState('done'))
            .catch(() => setState('failed'));
    }, [params]);

    return (
        <AuthLayout title="Verify your email">
            {state === 'checking' && <p role="status">Checking your link…</p>}
            {state === 'done' && <Alert tone="success">Email verified.</Alert>}
            {state === 'failed' && <Alert>This link is invalid or has expired. Sign in to request a new one.</Alert>}
            <p className="mt-6 text-sm">
                <Link to="/account" className="text-accent hover:underline">
                    Continue
                </Link>
            </p>
        </AuthLayout>
    );
}
