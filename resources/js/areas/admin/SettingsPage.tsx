import { useState } from 'react';
import { toast } from 'sonner';
import { PageHeader } from '@/components/PageHeader';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { TextField } from '@/components/ui/TextField';
import { ApiError } from '@/lib/api';
import { useAuthStore } from '@/lib/auth';
import { useFormSubmit } from '@/lib/useFormSubmit';
import { useSaveSettings, useSettings, type SettingsPayload } from './services/settings';
import { MailTestPanel } from './MailTestPanel';
import { StripeConnectionPanel } from './StripeConnectionPanel';
import { settingGroups, type SettingField } from './settingsSchema';

type Draft = Record<string, unknown>;

function initialDraft(data: SettingsPayload): Draft {
    const draft: Draft = {};
    for (const group of settingGroups)
        for (const field of group.fields) {
            const [g, n] = field.key.split('.');
            const raw = data[g]?.[n];
            draft[field.key] =
                field.kind === 'secret'
                    ? ''
                    : field.kind === 'list'
                      ? ((raw?.value as string[] | undefined) ?? []).join(', ')
                      : (raw?.value ?? (field.kind === 'toggle' ? false : ''));
        }
    return draft;
}

/** Turns the typed draft back into API values, returning only the fields that differ from what was loaded. */
function changes(initial: Draft, draft: Draft): Record<string, Record<string, unknown>> {
    const out: Record<string, Record<string, unknown>> = {};
    for (const group of settingGroups)
        for (const field of group.fields) {
            if (draft[field.key] === initial[field.key]) continue;
            const [g, n] = field.key.split('.');
            const value = draft[field.key];
            (out[g] ??= {})[n] =
                field.kind === 'list'
                    ? String(value)
                          .split(',')
                          .map((v) => v.trim())
                          .filter(Boolean)
                    : field.kind === 'number'
                      ? value === ''
                          ? null
                          : Number(value)
                      : value === '' && field.kind === 'email'
                        ? null
                        : value;
        }
    return out;
}

function Field({
    field,
    draft,
    set,
    secretIsSet,
    canEditSecrets,
    error,
}: {
    field: SettingField;
    draft: Draft;
    set: (key: string, value: unknown) => void;
    secretIsSet: boolean;
    canEditSecrets: boolean;
    error?: string;
}) {
    const value = draft[field.key];

    if (field.kind === 'toggle') {
        const id = `setting-${field.key}`;
        return (
            <div className="flex flex-col gap-1">
                <div className="flex items-center gap-2">
                    <input
                        id={id}
                        type="checkbox"
                        className="size-4 accent-primary"
                        checked={Boolean(value)}
                        onChange={(e) => set(field.key, e.target.checked)}
                    />
                    <label htmlFor={id} className="text-sm font-medium">
                        {field.label}
                    </label>
                </div>
                {field.hint && <p className="text-sm text-muted-foreground">{field.hint}</p>}
            </div>
        );
    }

    if (field.kind === 'secret' && !canEditSecrets) {
        return (
            <div className="flex items-center justify-between text-sm">
                <span className="font-medium">{field.label}</span>
                <span className="text-muted-foreground">{secretIsSet ? 'Set' : 'Not set'}</span>
            </div>
        );
    }

    if (field.kind === 'select') {
        const id = `setting-${field.key}`;
        return (
            <div className="flex flex-col gap-1.5">
                <label htmlFor={id} className="text-sm font-medium">
                    {field.label}
                </label>
                <select
                    id={id}
                    value={String(value ?? '')}
                    onChange={(e) => set(field.key, e.target.value)}
                    className="h-11 rounded-md border border-border bg-card px-3 text-base outline-none focus:border-primary"
                >
                    {field.options?.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
                {error ? (
                    <p className="text-sm text-destructive">{error}</p>
                ) : field.hint ? (
                    <p className="text-sm text-muted-foreground">{field.hint}</p>
                ) : null}
            </div>
        );
    }

    return (
        <TextField
            label={field.label}
            type={field.kind === 'secret' ? 'password' : field.kind === 'number' ? 'number' : field.kind === 'email' ? 'email' : 'text'}
            autoComplete="off"
            placeholder={field.kind === 'secret' ? (secretIsSet ? 'Set. Enter a new value to replace it.' : 'Not set') : undefined}
            value={String(value ?? '')}
            onChange={(e) => set(field.key, field.kind === 'number' ? e.target.value : e.target.value)}
            error={error}
            hint={field.hint}
        />
    );
}

function SettingsForm({ data }: { data: SettingsPayload }) {
    const canEditSecrets = useAuthStore((s) => s.user?.roles.includes('super_admin')) ?? false;
    const [initial] = useState(() => initialDraft(data));
    const [draft, setDraft] = useState<Draft>(initial);
    const save = useSaveSettings();
    const form = useFormSubmit(
        () => {
            const body = changes(initial, draft);
            if (Object.keys(body).length === 0) throw new ApiError('Nothing to save.', 0);
            return save.mutateAsync({ settings: body });
        },
        () => toast.success('Settings saved'),
    );

    return (
        <form onSubmit={form.submit} noValidate className="grid gap-6">
            {form.formError && <Alert>{form.formError}</Alert>}
            {settingGroups.map((group) => (
                <section key={group.title} aria-label={group.title} className="rounded-lg border bg-card p-6">
                    <h2 className="text-lg font-semibold">{group.title}</h2>
                    <p className="mb-5 text-sm text-muted-foreground">{group.description}</p>
                    <div className="grid max-w-xl gap-5">
                        {group.fields.map((field) => {
                            const [g, n] = field.key.split('.');
                            return (
                                <Field
                                    key={field.key}
                                    field={field}
                                    draft={draft}
                                    set={(key, value) => setDraft((d) => ({ ...d, [key]: value }))}
                                    secretIsSet={Boolean(data[g]?.[n]?.is_set)}
                                    canEditSecrets={canEditSecrets}
                                    error={form.fieldError(`settings.${field.key}`)}
                                />
                            );
                        })}
                    </div>
                </section>
            ))}
            <div>
                <Button size="lg" type="submit" loading={form.pending}>
                    Save settings
                </Button>
            </div>
        </form>
    );
}

export function SettingsPage() {
    const { data, dataUpdatedAt, error } = useSettings();

    return (
        <>
            <PageHeader title="Settings" description="Platform-wide rules and integrations." />
            <StripeConnectionPanel />
            {error && <Alert>{error instanceof ApiError ? error.message : 'Something went wrong.'}</Alert>}
            {!data && !error && <Skeleton className="h-64 w-full" />}
            {data && <SettingsForm key={dataUpdatedAt} data={data} />}
            <MailTestPanel />
        </>
    );
}
