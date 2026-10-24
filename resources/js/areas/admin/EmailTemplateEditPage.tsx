import { ArrowLeft } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { toast } from 'sonner';
import { PageHeader } from '@/components/PageHeader';
import { StatusBadge } from '@/components/StatusBadge';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { TextAreaField } from '@/components/ui/TextAreaField';
import { TextField } from '@/components/ui/TextField';
import { ApiError } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { useFormSubmit } from '@/lib/useFormSubmit';
import {
    usePreviewTemplate,
    useRestoreVersion,
    useSaveTemplate,
    useTemplateVersions,
    useTestTemplate,
    useEmailTemplate,
    type EmailTemplate,
    type RenderedEmail,
    type TemplateUpdate,
} from './services/email';

const message = (e: unknown) => (e instanceof ApiError ? e.message : 'Something went wrong.');

function Preview({ id, content }: { id: string; content: TemplateUpdate }) {
    const preview = usePreviewTemplate(id);
    const [rendered, setRendered] = useState<RenderedEmail>();
    const [failed, setFailed] = useState<string>();
    const run = useRef(preview.mutateAsync);
    run.current = preview.mutateAsync;
    const { subject, html_body, text_body } = content;

    // Live preview: render the unsaved text on the server (same renderer as real sends) shortly after typing stops.
    useEffect(() => {
        let current = true;
        const timer = setTimeout(() => {
            run.current({ subject, html_body, text_body }).then(
                (result) => current && (setRendered(result), setFailed(undefined)),
                (e) => current && setFailed(message(e)),
            );
        }, 350);
        return () => {
            current = false;
            clearTimeout(timer);
        };
    }, [subject, html_body, text_body]);

    return (
        <section aria-label="Preview" className="rounded-lg border bg-card p-6">
            <h2 className="mb-1 text-lg font-semibold">Preview</h2>
            <p className="mb-4 text-sm text-muted-foreground">Shown with sample values, as customers would see it.</p>
            {failed && <Alert>{failed}</Alert>}
            <p className="mb-3 text-sm">
                <span className="text-muted-foreground">Subject: </span>
                <span className="font-medium">{rendered?.subject ?? '…'}</span>
            </p>
            <iframe title="Email preview" sandbox="" srcDoc={rendered?.html ?? ''} className="h-72 w-full rounded-md border bg-white" />
        </section>
    );
}

function Variables({ template }: { template: EmailTemplate }) {
    const entries = Object.entries(template.variables ?? {});

    return (
        <section aria-label="Variables" className="rounded-lg border bg-card p-6">
            <h2 className="mb-1 text-lg font-semibold">Variables</h2>
            <p className="mb-4 text-sm text-muted-foreground">Write these between double curly braces. Anything else is left out when the email is sent.</p>
            <dl className="grid gap-2 text-sm">
                {entries.map(([name, description]) => (
                    <div key={name}>
                        <dt>
                            <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{`{{${name}}}`}</code>
                        </dt>
                        <dd className="text-muted-foreground">{description}</dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

function History({ template }: { template: EmailTemplate }) {
    const { data } = useTemplateVersions(template.id);
    const restore = useRestoreVersion(template.id);
    const [error, setError] = useState<string>();

    async function run(version: number) {
        setError(undefined);
        try {
            await restore.mutateAsync(version);
            toast.success(`Version ${version} restored as a new version`);
        } catch (e) {
            setError(message(e));
        }
    }

    return (
        <section aria-label="Version history" className="rounded-lg border bg-card p-6">
            <h2 className="mb-4 text-lg font-semibold">Version history</h2>
            {error && <Alert>{error}</Alert>}
            <ul className="grid gap-3">
                {data?.map((v) => (
                    <li key={v.id} className="flex items-center justify-between gap-3 text-sm">
                        <div>
                            <span className="block font-medium">{`Version ${v.version}`}</span>
                            <span className="block text-xs text-muted-foreground">
                                {v.author ?? 'System'} · {formatDateTime(v.created_at)}
                            </span>
                        </div>
                        {v.version !== template.version && (
                            <Button
                                variant="outline"
                                size="sm"
                                aria-label={`Restore version ${v.version}`}
                                disabled={restore.isPending}
                                onClick={() => run(v.version)}
                            >
                                Restore
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}

function TestSend({ template }: { template: EmailTemplate }) {
    const [to, setTo] = useState('');
    const send = useTestTemplate(template.id);
    const [result, setResult] = useState<{ ok: boolean; text: string }>();
    const form = useFormSubmit(
        () => send.mutateAsync(to),
        (log) =>
            setResult(
                log.status === 'sent'
                    ? { ok: true, text: `Test email sent to ${log.to_email}.` }
                    : { ok: false, text: `Could not send: ${log.error ?? 'unknown error'}` },
            ),
    );

    return (
        <section aria-label="Test send" className="rounded-lg border bg-card p-6">
            <h2 className="mb-1 text-lg font-semibold">Test send</h2>
            <p className="mb-4 text-sm text-muted-foreground">Sends the saved version with sample values through the current mail settings.</p>
            <form onSubmit={form.submit} noValidate className="grid gap-3">
                {form.formError && <Alert>{form.formError}</Alert>}
                {result && (result.ok ? <p className="text-sm text-success">{result.text}</p> : <Alert>{result.text}</Alert>)}
                <TextField label="Send a test to" type="email" value={to} onChange={(e) => setTo(e.target.value)} error={form.fieldError('to')} />
                <div>
                    <Button type="submit" variant="outline" loading={form.pending}>
                        Send test
                    </Button>
                </div>
            </form>
        </section>
    );
}

function Editor({ template }: { template: EmailTemplate }) {
    const [draft, setDraft] = useState<TemplateUpdate>({
        subject: template.subject,
        html_body: template.html_body ?? '',
        text_body: template.text_body ?? '',
        enabled: template.enabled,
    });
    const save = useSaveTemplate(template.id);
    const form = useFormSubmit(
        () => save.mutateAsync(draft),
        () => toast.success('Template saved'),
    );
    const set = <K extends keyof TemplateUpdate>(key: K, value: TemplateUpdate[K]) => setDraft((d) => ({ ...d, [key]: value }));

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div className="grid content-start gap-6">
                <form onSubmit={form.submit} noValidate className="grid gap-5 rounded-lg border bg-card p-6">
                    {form.formError && <Alert>{form.formError}</Alert>}
                    <TextField label="Subject" value={draft.subject} onChange={(e) => set('subject', e.target.value)} error={form.fieldError('subject')} />
                    <TextAreaField
                        label="HTML body"
                        rows={10}
                        className="font-mono text-sm"
                        value={draft.html_body}
                        onChange={(e) => set('html_body', e.target.value)}
                        error={form.fieldError('html_body')}
                    />
                    <TextAreaField
                        label="Text body"
                        rows={6}
                        className="font-mono text-sm"
                        value={draft.text_body}
                        onChange={(e) => set('text_body', e.target.value)}
                        error={form.fieldError('text_body')}
                        hint="Plain-text alternative for mail clients that do not show HTML."
                    />
                    <div className="flex items-center gap-2">
                        <input
                            id="template-enabled"
                            type="checkbox"
                            className="size-4 accent-primary"
                            checked={draft.enabled}
                            onChange={(e) => set('enabled', e.target.checked)}
                        />
                        <label htmlFor="template-enabled" className="text-sm font-medium">
                            Enabled
                        </label>
                        <span className="text-sm text-muted-foreground">When off, this email is logged as skipped and not sent.</span>
                    </div>
                    <div>
                        <Button size="lg" type="submit" loading={form.pending}>
                            Save template
                        </Button>
                    </div>
                </form>
                <Preview id={template.id} content={draft} />
            </div>
            <div className="grid content-start gap-6">
                <Variables template={template} />
                <TestSend template={template} />
                <History template={template} />
            </div>
        </div>
    );
}

export function EmailTemplateEditPage() {
    const { id } = useParams();
    const { data, error } = useEmailTemplate(id);

    return (
        <>
            <Link to="/admin/email-templates" className="mb-3 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                <ArrowLeft className="size-4" aria-hidden /> Email templates
            </Link>
            <PageHeader title={data?.name ?? 'Email template'} description={data ? (data.description ?? data.key) : undefined} />
            {data && (
                <p className="-mt-4 mb-6 flex items-center gap-2 text-sm text-muted-foreground">
                    <code>{data.key}</code> <StatusBadge label={`Version ${data.version}`} tone="neutral" />
                </p>
            )}
            {error && <Alert>{message(error)}</Alert>}
            {!data && !error && <Skeleton className="h-96 w-full" />}
            {data && <Editor key={`${data.version}-${data.updated_at}`} template={data} />}
        </>
    );
}
