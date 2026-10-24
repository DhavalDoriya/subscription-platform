import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/TextField';
import { api } from '@/lib/api';
import { useFormSubmit } from '@/lib/useFormSubmit';
import { AuthLayout } from './AuthLayout';

export function ResetPasswordPage() {
    const [params] = useSearchParams();
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const navigate = useNavigate();
    const form = useFormSubmit(
        () =>
            api.post('/auth/password/reset', {
                token: params.get('token'),
                email: params.get('email'),
                password,
                password_confirmation: confirmation,
            }),
        () => navigate('/login', { replace: true }),
    );

    return (
        <AuthLayout title="Choose a new password">
            <form onSubmit={form.submit} className="flex flex-col gap-5" noValidate>
                {form.formError && <Alert>{form.formError}</Alert>}
                {form.fieldError('token') && <Alert>{form.fieldError('token')}</Alert>}
                <TextField label="New password" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} error={form.fieldError('password')} />
                <TextField label="Confirm new password" type="password" autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
                <Button type="submit" loading={form.pending}>
                    Update password
                </Button>
            </form>
            <p className="mt-6 text-sm">
                <Link to="/forgot-password" className="text-accent hover:underline">
                    Request a new link
                </Link>
            </p>
        </AuthLayout>
    );
}
