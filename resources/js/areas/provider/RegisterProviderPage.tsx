import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/TextField';
import { refreshSession, useAuthStore } from '@/lib/auth';
import { isValidSlug, normalizeSlug } from '@/lib/slug';
import { useFormSubmit } from '@/lib/useFormSubmit';
import { registerProvider, storefrontHost, useSlugAvailability, usePlatformConfig } from './services/provider';

const reasons = {
    invalid: 'Use 3 to 40 lowercase letters, numbers or single hyphens, not starting or ending with a hyphen.',
    reserved: 'This address is reserved.',
    taken: 'This address is already taken.',
} as const;

export function RegisterProviderPage() {
    const user = useAuthStore((s) => s.user);
    const { data: config } = usePlatformConfig();
    const navigate = useNavigate();
    const [name, setName] = useState('');
    const [slug, setSlug] = useState('');
    const [category, setCategory] = useState('');
    const [country, setCountry] = useState('');
    const [email, setEmail] = useState(user?.email ?? '');
    const [currency, setCurrency] = useState('');
    const availability = useSlugAvailability(slug);

    const selectedCurrency = currency || config?.default_currency || 'usd';
    const form = useFormSubmit(
        () =>
            registerProvider({
                name,
                slug,
                business_category: category,
                country: country.toUpperCase(),
                support_email: email,
                currency: selectedCurrency,
            }).then(
                // The new role lives in the access token: get a fresh one before heading to the dashboard.
                (provider) => refreshSession().then(() => provider),
            ),
        () => navigate('/dashboard', { replace: true }),
    );

    const slugHint = !slug
        ? 'This becomes your storefront address.'
        : !isValidSlug(slug)
          ? reasons.invalid
          : availability.data?.available
            ? `${storefrontHost(slug, config)} is available.`
            : availability.data?.reason
              ? reasons[availability.data.reason]
              : 'Checking…';

    return (
        <main className="mx-auto max-w-xl px-4 py-12">
            <Link to="/account" className="text-sm text-primary hover:underline">
                Back to account
            </Link>
            <h1 className="mt-4 text-3xl font-semibold">Become a provider</h1>
            <p className="mt-2 text-muted-foreground">Sell subscriptions on your own branded page. It takes a few minutes.</p>

            <form onSubmit={form.submit} noValidate className="mt-8 grid gap-5">
                {form.formError && <Alert>{form.formError}</Alert>}
                <TextField label="Business name" value={name} onChange={(e) => setName(e.target.value)} error={form.fieldError('name')} />
                <TextField
                    label="Address"
                    value={slug}
                    onChange={(e) => setSlug(normalizeSlug(e.target.value))}
                    error={form.fieldError('slug')}
                    hint={slugHint}
                    autoCapitalize="off"
                    autoComplete="off"
                />
                <TextField
                    label="Category"
                    list="provider-categories"
                    value={category}
                    onChange={(e) => setCategory(e.target.value)}
                    error={form.fieldError('business_category')}
                />
                <datalist id="provider-categories">
                    {['fitness', 'education', 'media', 'software', 'food', 'other'].map((c) => (
                        <option key={c} value={c} />
                    ))}
                </datalist>
                <TextField
                    label="Country"
                    value={country}
                    maxLength={2}
                    onChange={(e) => setCountry(e.target.value)}
                    error={form.fieldError('country')}
                    hint="Two-letter code, e.g. US."
                />
                <TextField
                    label="Support email"
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    error={form.fieldError('support_email')}
                />
                <div className="flex flex-col gap-1.5">
                    <label htmlFor="provider-currency" className="text-sm font-medium">
                        Currency
                    </label>
                    <select
                        id="provider-currency"
                        value={selectedCurrency}
                        disabled={!config}
                        onChange={(e) => setCurrency(e.target.value)}
                        className="h-11 rounded-md border border-input bg-card px-3 text-base outline-none focus:border-primary"
                    >
                        {(config?.allowed_currencies ?? ['usd']).map((c) => (
                            <option key={c} value={c}>
                                {c.toUpperCase()}
                            </option>
                        ))}
                    </select>
                    {form.fieldError('currency') && <p className="text-sm text-destructive">{form.fieldError('currency')}</p>}
                </div>
                <Button size="lg" type="submit" loading={form.pending}>
                    Create provider account
                </Button>
            </form>
        </main>
    );
}
