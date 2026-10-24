import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/TextField';
import { homeFor, login } from '@/lib/auth';
import { useFormSubmit } from '@/lib/useFormSubmit';
import { AuthLayout } from './AuthLayout';

export function LoginPage() {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const navigate = useNavigate();
    const from = (useLocation().state as { from?: string } | null)?.from;
    const form = useFormSubmit(
        () => login(email, password),
        (session) => navigate(from ?? homeFor(session.user), { replace: true }),
    );

    return (
        <AuthLayout title="Sign in" subtitle="Welcome back.">
            <form onSubmit={form.submit} className="flex flex-col gap-5" noValidate>
                {form.formError && <Alert>{form.formError}</Alert>}
                <TextField
                    label="Email"
                    type="email"
                    autoComplete="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    error={form.fieldError('email')}
                />
                <TextField
                    label="Password"
                    type="password"
                    autoComplete="current-password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    error={form.fieldError('password')}
                />
                <Button size="lg" type="submit" loading={form.pending}>
                    Sign in
                </Button>
            </form>
            <p className="mt-6 flex justify-between text-sm">
                <Link to="/forgot-password" className="text-primary hover:underline">
                    Forgot password?
                </Link>
                <Link to="/register" className="text-primary hover:underline">
                    Create an account
                </Link>
            </p>
        </AuthLayout>
    );
}
