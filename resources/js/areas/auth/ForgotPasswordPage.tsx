import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/TextField';
import { api } from '@/lib/api';
import { useFormSubmit } from '@/lib/useFormSubmit';
import { AuthLayout } from './AuthLayout';

export function ForgotPasswordPage() {
    const [email, setEmail] = useState('');
    const [message, setMessage] = useState<string | null>(null);
    const form = useFormSubmit(
        () => api.post<{ message: string }>('/auth/password/forgot', { email }),
        (result) => setMessage(result.message),
    );

    return (
        <AuthLayout title="Reset your password" subtitle="We'll email you a link to choose a new one.">
            {message ? (
                <Alert tone="success">{message}</Alert>
            ) : (
                <form onSubmit={form.submit} className="flex flex-col gap-5" noValidate>
                    {form.formError && <Alert>{form.formError}</Alert>}
                    <TextField label="Email" type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} error={form.fieldError('email')} />
                    <Button type="submit" loading={form.pending}>
                        Send reset link
                    </Button>
                </form>
            )}
            <p className="mt-6 text-sm">
                <Link to="/login" className="text-accent hover:underline">
                    Back to sign in
                </Link>
            </p>
        </AuthLayout>
    );
}
