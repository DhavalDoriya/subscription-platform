import { useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { FormDialog } from '@/components/FormDialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { TextField } from '@/components/ui/TextField';
import { ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { useFormSubmit } from '@/lib/useFormSubmit';
import { providerActions, useProviderAction, useUpdateProvider, type AdminProvider, type ProviderAction } from './services/providers';
import { ProviderStatusBadge } from './ProviderStatusBadge';

const labels: Record<ProviderAction, string> = {
    approve: 'Approve',
    reject: 'Reject',
    activate: 'Activate',
    deactivate: 'Deactivate',
    suspend: 'Suspend',
};

const past: Record<ProviderAction, string> = {
    approve: 'approved',
    reject: 'rejected',
    activate: 'activated',
    deactivate: 'deactivated',
    suspend: 'suspended',
};

const warnings: Partial<Record<ProviderAction, string>> = {
    deactivate:
        'This will pause billing for existing subscribers, make the storefront unavailable and block new checkouts until the provider is activated again.',
    suspend: 'This will pause billing for existing subscribers, make the storefront unavailable and block new checkouts until the provider is activated again.',
};

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 text-sm">{children}</dd>
        </div>
    );
}

function EditForm({ provider }: { provider: AdminProvider }) {
    const update = useUpdateProvider();
    const [name, setName] = useState(provider.name);
    const [slug, setSlug] = useState(provider.slug);
    const [email, setEmail] = useState(provider.support_email);
    const form = useFormSubmit(
        () => update.mutateAsync({ id: provider.id, name, slug, support_email: email }),
        () => toast.success('Provider saved'),
    );

    return (
        <form onSubmit={form.submit} noValidate className="grid gap-4 border-t pt-4">
            <h3 className="text-sm font-medium">Edit</h3>
            {form.formError && (
                <p role="alert" className="text-sm text-destructive">
                    {form.formError}
                </p>
            )}
            <TextField label="Name" value={name} onChange={(e) => setName(e.target.value)} error={form.fieldError('name')} />
            <TextField
                label="Address (slug)"
                value={slug}
                onChange={(e) => setSlug(e.target.value)}
                error={form.fieldError('slug')}
                hint={`Storefront at ${slug || '…'}.${window.location.hostname}. Changing it breaks existing links.`}
            />
            <TextField label="Support email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} error={form.fieldError('support_email')} />
            <div>
                <Button type="submit" loading={form.pending}>
                    Save changes
                </Button>
            </div>
        </form>
    );
}

export function ProviderDialog({ provider, onClose }: { provider: AdminProvider | null; onClose: () => void }) {
    const run = useProviderAction();
    const [confirming, setConfirming] = useState<ProviderAction | null>(null);
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState('');

    if (!provider) return null;

    async function perform(action: ProviderAction, why?: string) {
        await run.mutateAsync({ id: provider!.id, action, reason: why });
        toast.success(`${provider!.name} ${past[action]}`);
        onClose();
    }

    async function direct(action: ProviderAction) {
        try {
            await perform(action);
        } catch (e) {
            toast.error(e instanceof ApiError ? e.message : 'Something went wrong.');
        }
    }

    return (
        <>
            <Dialog open onOpenChange={(open) => !open && onClose()}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>{provider.name}</DialogTitle>
                        <DialogDescription>
                            {provider.slug} · <ProviderStatusBadge status={provider.status} />
                        </DialogDescription>
                    </DialogHeader>

                    <dl className="grid grid-cols-2 gap-x-6 gap-y-4">
                        <Detail label="Owner">
                            {provider.owner ? (
                                <>
                                    <span className="block">{provider.owner.name}</span>
                                    <span className="block text-muted-foreground">{provider.owner.email}</span>
                                </>
                            ) : (
                                '—'
                            )}
                        </Detail>
                        <Detail label="Support email">{provider.support_email}</Detail>
                        <Detail label="Category">{provider.business_category}</Detail>
                        <Detail label="Country · currency">
                            {provider.country} · {provider.currency.toUpperCase()}
                        </Detail>
                        <Detail label="Customers">{provider.customers_count}</Detail>
                        <Detail label="Stripe">{provider.stripe_status.replaceAll('_', ' ')}</Detail>
                        <Detail label="Registered">{formatDate(provider.created_at)}</Detail>
                        <Detail label="Approved">{formatDate(provider.approved_at)}</Detail>
                        {provider.rejection_reason && <Detail label="Rejection reason">{provider.rejection_reason}</Detail>}
                    </dl>

                    <div className="flex flex-wrap gap-2 border-t pt-4">
                        {providerActions[provider.status].map((action) => (
                            <Button
                                key={action}
                                variant={action === 'reject' || action === 'suspend' || action === 'deactivate' ? 'outline' : 'default'}
                                disabled={run.isPending}
                                onClick={() => (action === 'reject' ? setRejecting(true) : warnings[action] ? setConfirming(action) : direct(action))}
                            >
                                {labels[action]}
                            </Button>
                        ))}
                    </div>

                    <EditForm key={provider.id} provider={provider} />
                </DialogContent>
            </Dialog>

            <FormDialog
                open={rejecting}
                onOpenChange={(open) => {
                    setRejecting(open);
                    if (!open) setReason('');
                }}
                title="Reject provider"
                description="The owner will see this reason."
                submitLabel="Reject provider"
                destructive
                submit={() => perform('reject', reason)}
            >
                {({ fieldError }) => (
                    <div className="grid gap-1.5">
                        <Label htmlFor="reject-reason">Reason</Label>
                        <Textarea
                            id="reject-reason"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            aria-invalid={fieldError('reason') ? true : undefined}
                        />
                        {fieldError('reason') && <p className="text-sm text-destructive">{fieldError('reason')}</p>}
                    </div>
                )}
            </FormDialog>

            <ConfirmDialog
                open={confirming !== null}
                onOpenChange={(open) => !open && setConfirming(null)}
                title={`${confirming ? labels[confirming] : ''} ${provider.name}?`}
                description={confirming ? (warnings[confirming] ?? '') : ''}
                confirmLabel={confirming ? labels[confirming] : ''}
                destructive
                onConfirm={() => perform(confirming!)}
            />
        </>
    );
}
