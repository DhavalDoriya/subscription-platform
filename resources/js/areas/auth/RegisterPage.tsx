import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/TextField';
import { register } from '@/lib/auth';
import { useFormSubmit } from '@/lib/useFormSubmit';
import { AuthLayout } from './AuthLayout';

export function RegisterPage() {
    const [values, setValues] = useState({ name: '', email: '', password: '', password_confirmation: '' });
    const navigate = useNavigate();
    const form = useFormSubmit(() => register(values), () => navigate('/account', { replace: true }));
    const bind = (field: keyof typeof values) => ({
        value: values[field],
        onChange: (e: React.ChangeEvent<HTMLInputElement>) => setValues({ ...values, [field]: e.target.value }),
        error: form.fieldError(field),
    });

    return (
        <AuthLayout title="Create your account" subtitle="One account works with every business on the platform.">
            <form onSubmit={form.submit} className="flex flex-col gap-5" noValidate>
                {form.formError && <Alert>{form.formError}</Alert>}
                <TextField label="Full name" autoComplete="name" {...bind('name')} />
                <TextField label="Email" type="email" autoComplete="email" {...bind('email')} />
                <TextField label="Password" type="password" autoComplete="new-password" hint="At least 8 characters with upper and lower case letters and a number." {...bind('password')} />
                <TextField label="Confirm password" type="password" autoComplete="new-password" {...bind('password_confirmation')} />
                <Button type="submit" loading={form.pending}>
                    Create account
                </Button>
            </form>
            <p className="mt-6 text-sm">
                Already have an account?{' '}
                <Link to="/login" className="text-accent hover:underline">
                    Sign in
                </Link>
            </p>
        </AuthLayout>
    );
}
