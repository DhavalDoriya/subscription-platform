import { useState } from 'react';
import { PageHeader } from '@/components/PageHeader';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { TextField } from '@/components/ui/TextField';
import { ApiError } from '@/lib/api';
import { useFormSubmit } from '@/lib/useFormSubmit';
import { storefrontHost, useMyProvider, usePlatformConfig, useSaveProvider, type MyProvider, type PlatformConfig } from './services/provider';

const timezones = (current: string) => {
    const all = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [];
    return Array.from(new Set(['UTC', current, ...all]));
};

function Select({
    id,
    label,
    value,
    options,
    onChange,
    error,
}: {
    id: string;
    label: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (v: string) => void;
    error?: string;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={id} className="text-sm font-medium">
                {label}
            </label>
            <select
                id={id}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="h-11 rounded-md border border-input bg-card px-3 text-base outline-none focus:border-primary"
            >
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}

function SettingsForm({ provider, config }: { provider: MyProvider; config?: PlatformConfig }) {
    const save = useSaveProvider();
    const [name, setName] = useState(provider.name);
    const [email, setEmail] = useState(provider.support_email);
    const [timezone, setTimezone] = useState(provider.timezone);
    const [currency, setCurrency] = useState(provider.currency);
    const [color, setColor] = useState(provider.branding.primary_color ?? '');
    const [terms, setTerms] = useState(provider.legal.terms_url ?? '');
    const [privacy, setPrivacy] = useState(provider.legal.privacy_url ?? '');
    const [refund, setRefund] = useState(provider.legal.refund_url ?? '');
    const [tax, setTax] = useState(provider.tax_enabled);
    const [saved, setSaved] = useState(false);

    const form = useFormSubmit(
        () => {
            setSaved(false);
            return save.mutateAsync({
                name,
                support_email: email,
                timezone,
                currency,
                branding: { primary_color: color || null },
                legal: { terms_url: terms || null, privacy_url: privacy || null, refund_url: refund || null },
                ...(config?.tax_available ? { tax_enabled: tax } : {}),
            });
        },
        () => setSaved(true),
    );

    const currencies = Array.from(new Set([provider.currency, ...(config?.allowed_currencies ?? [])]));

    return (
        <form onSubmit={form.submit} noValidate className="grid max-w-xl gap-5">
            {form.formError && <Alert>{form.formError}</Alert>}
            {saved && <Alert tone="success">Settings saved.</Alert>}

            <TextField label="Business name" value={name} onChange={(e) => setName(e.target.value)} error={form.fieldError('name')} />
            <div>
                <p className="text-sm font-medium">Storefront address</p>
                <p className="text-sm text-muted-foreground">{storefrontHost(provider.slug, config)}. Contact support to change it.</p>
            </div>
            <TextField label="Support email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} error={form.fieldError('support_email')} />
            <Select
                id="provider-timezone"
                label="Timezone"
                value={timezone}
                onChange={setTimezone}
                options={timezones(provider.timezone).map((t) => ({ value: t, label: t }))}
                error={form.fieldError('timezone')}
            />
            <Select
                id="provider-currency"
                label="Currency"
                value={currency}
                onChange={setCurrency}
                options={currencies.map((c) => ({ value: c, label: c.toUpperCase() }))}
                error={form.fieldError('currency')}
            />

            {config?.tax_available && (
                <div className="flex flex-col gap-1">
                    <div className="flex items-center gap-2">
                        <input id="provider-tax" type="checkbox" className="size-4 accent-primary" checked={tax} onChange={(e) => setTax(e.target.checked)} />
                        <label htmlFor="provider-tax" className="text-sm font-medium">
                            Charge tax with Stripe Tax
                        </label>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Prices are treated as excluding tax. Finish your tax registrations in your Stripe dashboard first.
                    </p>
                    {form.fieldError('tax_enabled') && <p className="text-sm text-destructive">{form.fieldError('tax_enabled')}</p>}
                </div>
            )}

            <h2 className="mt-2 text-lg font-semibold">Branding</h2>
            <TextField
                label="Brand colour"
                placeholder="#3b5bdb"
                value={color}
                onChange={(e) => setColor(e.target.value)}
                error={form.fieldError('branding.primary_color')}
                hint="A hex colour used for buttons on your storefront."
            />

            <h2 className="mt-2 text-lg font-semibold">Legal links</h2>
            <TextField
                label="Terms of service URL"
                type="url"
                value={terms}
                onChange={(e) => setTerms(e.target.value)}
                error={form.fieldError('legal.terms_url')}
            />
            <TextField
                label="Privacy policy URL"
                type="url"
                value={privacy}
                onChange={(e) => setPrivacy(e.target.value)}
                error={form.fieldError('legal.privacy_url')}
            />
            <TextField
                label="Refund policy URL"
                type="url"
                value={refund}
                onChange={(e) => setRefund(e.target.value)}
                error={form.fieldError('legal.refund_url')}
            />

            <div>
                <Button size="lg" type="submit" loading={form.pending}>
                    Save settings
                </Button>
            </div>
        </form>
    );
}

export function ProviderSettingsPage() {
    const { data: provider, error } = useMyProvider();
    const { data: config } = usePlatformConfig();

    return (
        <>
            <PageHeader title="Settings" description="Your business details, branding and legal links." />
            {error && <Alert>{error instanceof ApiError ? error.message : 'Something went wrong.'}</Alert>}
            {!provider && !error && <Skeleton className="h-64 w-full max-w-xl" />}
            {provider && <SettingsForm key={provider.id} provider={provider} config={config} />}
        </>
    );
}
