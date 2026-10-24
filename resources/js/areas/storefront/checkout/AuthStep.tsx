import { useState } from 'react';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/TextField';
import { login, register } from '@/lib/auth';
import { useFormSubmit } from '@/lib/useFormSubmit';
import { cn } from '@/lib/utils';

type Mode = 'signin' | 'register';

function SignInForm() {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const form = useFormSubmit(
        () => login(email, password),
        () => {}, // the auth store updates, which moves the flow on
    );

    return (
        <form onSubmit={form.submit} noValidate className="grid gap-4">
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
    );
}

function RegisterForm() {
    const [values, setValues] = useState({ name: '', email: '', password: '', password_confirmation: '' });
    const form = useFormSubmit(
        () => register(values),
        () => {},
    );
    const bind = (field: keyof typeof values) => ({
        value: values[field],
        onChange: (e: React.ChangeEvent<HTMLInputElement>) => setValues({ ...values, [field]: e.target.value }),
        error: form.fieldError(field),
    });

    return (
        <form onSubmit={form.submit} noValidate className="grid gap-4">
            {form.formError && <Alert>{form.formError}</Alert>}
            <TextField label="Full name" autoComplete="name" {...bind('name')} />
            <TextField label="Email" type="email" autoComplete="email" {...bind('email')} />
            <TextField
                label="Password"
                type="password"
                autoComplete="new-password"
                hint="At least 8 characters with upper and lower case letters and a number."
                {...bind('password')}
            />
            <TextField label="Confirm password" type="password" autoComplete="new-password" {...bind('password_confirmation')} />
            <Button size="lg" type="submit" loading={form.pending}>
                Create account
            </Button>
        </form>
    );
}

/** Sign in or create the one platform account, without leaving the storefront. Joining happens only after, on purpose. */
export function AuthStep() {
    const [mode, setMode] = useState<Mode>('signin');
    const tabs: [Mode, string][] = [
        ['signin', 'Sign in'],
        ['register', 'Create account'],
    ];

    return (
        <div className="grid gap-5">
            <div role="tablist" aria-label="Account" className="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1">
                {tabs.map(([value, label]) => (
                    <button
                        key={value}
                        role="tab"
                        type="button"
                        aria-selected={mode === value}
                        onClick={() => setMode(value)}
                        className={cn('rounded-md px-3 py-2 text-sm font-medium', mode === value ? 'bg-card shadow-sm' : 'text-muted-foreground')}
                    >
                        {label}
                    </button>
                ))}
            </div>
            {mode === 'signin' ? <SignInForm /> : <RegisterForm />}
        </div>
    );
}
