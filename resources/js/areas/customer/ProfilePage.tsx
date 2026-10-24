import { useRef, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/TextField';
import { changePassword, removeAvatar, updateProfile, uploadAvatar, useAuthStore } from '@/lib/auth';
import { ApiError } from '@/lib/api';
import { useFormSubmit } from '@/lib/useFormSubmit';

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <section className="border-t border-border py-8">
            <h2 className="text-lg font-semibold">{title}</h2>
            <div className="mt-5 flex max-w-sm flex-col gap-5">{children}</div>
        </section>
    );
}

function Avatar() {
    const user = useAuthStore((s) => s.user)!;
    const input = useRef<HTMLInputElement>(null);
    const [error, setError] = useState<string>();
    const [busy, setBusy] = useState(false);

    async function run(action: () => Promise<unknown>) {
        setBusy(true);
        setError(undefined);
        try {
            await action();
        } catch (e) {
            setError(e instanceof ApiError ? (e.firstError('avatar') ?? e.message) : 'Something went wrong.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <Section title="Photo">
            {error && <Alert>{error}</Alert>}
            <div className="flex items-center gap-4">
                {user.avatar_url ? (
                    <img src={user.avatar_url} alt="Your photo" className="size-16 rounded-full object-cover" />
                ) : (
                    <span aria-hidden className="grid size-16 place-items-center rounded-full bg-background text-xl font-semibold text-muted-foreground">
                        {user.name.charAt(0).toUpperCase()}
                    </span>
                )}
                <div className="flex gap-2">
                    <Button size="lg" variant="outline" type="button" loading={busy} onClick={() => input.current?.click()}>
                        Change
                    </Button>
                    {user.avatar_url && (
                        <Button size="lg" variant="outline" type="button" disabled={busy} onClick={() => run(removeAvatar)}>
                            Remove photo
                        </Button>
                    )}
                </div>
                <input
                    ref={input}
                    type="file"
                    hidden
                    aria-label="Upload photo"
                    accept="image/png,image/jpeg,image/webp"
                    onChange={(e) => {
                        const file = e.target.files?.[0];
                        e.target.value = '';
                        if (file) void run(() => uploadAvatar(file));
                    }}
                />
            </div>
            <p className="text-sm text-muted-foreground">PNG, JPG or WebP, up to 2 MB.</p>
        </Section>
    );
}

function Details() {
    const user = useAuthStore((s) => s.user)!;
    const [name, setName] = useState(user.name);
    const [email, setEmail] = useState(user.email);
    const [password, setPassword] = useState('');
    const [saved, setSaved] = useState(false);
    const emailChanged = email.trim().toLowerCase() !== user.email.toLowerCase();

    const form = useFormSubmit(
        () => {
            setSaved(false);
            return updateProfile({ name, ...(emailChanged ? { email, current_password: password } : {}) });
        },
        () => {
            setPassword('');
            setSaved(true);
        },
    );

    return (
        <Section title="Details">
            <form onSubmit={form.submit} className="flex flex-col gap-5" noValidate>
                {form.formError && <Alert>{form.formError}</Alert>}
                {saved && <Alert tone="success">Profile saved.</Alert>}
                <TextField label="Name" autoComplete="name" value={name} onChange={(e) => setName(e.target.value)} error={form.fieldError('name')} />
                <TextField
                    label="Email"
                    type="email"
                    autoComplete="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    error={form.fieldError('email')}
                    hint={user.email_verified_at ? undefined : 'Not verified yet. Check your inbox for the link.'}
                />
                {emailChanged && (
                    <TextField
                        label="Confirm with your password"
                        type="password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        error={form.fieldError('current_password')}
                        hint="Needed to change your email. You will be asked to verify the new address."
                    />
                )}
                <Button size="lg" type="submit" loading={form.pending}>
                    Save changes
                </Button>
            </form>
        </Section>
    );
}

function PasswordForm() {
    const [current, setCurrent] = useState('');
    const [next, setNext] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [done, setDone] = useState(false);

    const form = useFormSubmit(
        () => {
            setDone(false);
            return changePassword({ current_password: current, password: next, password_confirmation: confirmation });
        },
        () => {
            setCurrent('');
            setNext('');
            setConfirmation('');
            setDone(true);
        },
    );

    return (
        <Section title="Password">
            <form onSubmit={(e: FormEvent) => form.submit(e)} className="flex flex-col gap-5" noValidate>
                {form.formError && <Alert>{form.formError}</Alert>}
                {done && <Alert tone="success">Password updated. Other devices have been signed out.</Alert>}
                <TextField
                    id="pw-current"
                    label="Current password"
                    type="password"
                    autoComplete="current-password"
                    value={current}
                    onChange={(e) => setCurrent(e.target.value)}
                    error={form.fieldError('current_password')}
                />
                <TextField
                    label="New password"
                    type="password"
                    autoComplete="new-password"
                    value={next}
                    onChange={(e) => setNext(e.target.value)}
                    error={form.fieldError('password')}
                />
                <TextField
                    label="Confirm new password"
                    type="password"
                    autoComplete="new-password"
                    value={confirmation}
                    onChange={(e) => setConfirmation(e.target.value)}
                />
                <Button size="lg" type="submit" loading={form.pending}>
                    Update password
                </Button>
            </form>
        </Section>
    );
}

export function ProfilePage() {
    return (
        <main className="mx-auto max-w-2xl px-4 py-12">
            <Link to="/account" className="text-sm text-primary hover:underline">
                Back to account
            </Link>
            <h1 className="mt-4 text-3xl font-semibold">Profile</h1>
            <Avatar />
            <Details />
            <PasswordForm />
        </main>
    );
}
