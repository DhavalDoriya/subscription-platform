import { Archive, Pencil, Plus, Rocket, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { DataTable, type Column } from '@/components/data-table/DataTable';
import { FilterSelect } from '@/components/FilterSelect';
import { FormDialog } from '@/components/FormDialog';
import { PageHeader } from '@/components/PageHeader';
import { StatusBadge } from '@/components/StatusBadge';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { TextField } from '@/components/ui/TextField';
import { ApiError } from '@/lib/api';
import { formatPrice } from '@/lib/money';
import { useListState } from '@/lib/useListState';
import { PlanEditorDialog } from './PlanEditorDialog';
import { planStatusLabels, usePlanMutations, usePlans, type Plan, type PlanStatus } from './services/plans';
import { useMyProvider } from './services/provider';

const tones: Record<PlanStatus, 'neutral' | 'good' | 'warn'> = { draft: 'warn', active: 'good', archived: 'neutral' };
const message = (e: unknown) => (e instanceof ApiError ? e.message : 'Something went wrong.');

function NewPlanDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const { create } = usePlanMutations();
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [features, setFeatures] = useState('');
    const [trial, setTrial] = useState('0');

    return (
        <FormDialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);
                if (!next) {
                    setName('');
                    setDescription('');
                    setFeatures('');
                    setTrial('0');
                }
            }}
            title="New plan"
            description="Plans start as drafts. Add a price, then publish when you are ready."
            submitLabel="Create plan"
            submit={() =>
                create.mutateAsync({
                    name,
                    description: description || null,
                    features: features
                        .split('\n')
                        .map((f) => f.trim())
                        .filter(Boolean),
                    trial_days: Number(trial) || 0,
                    visibility: 'public',
                })
            }
            onSuccess={() => toast.success('Plan created')}
        >
            {({ fieldError }) => (
                <>
                    <TextField label="Name" value={name} onChange={(e) => setName(e.target.value)} error={fieldError('name')} />
                    <div className="grid gap-1.5">
                        <Label htmlFor="new-description">Description</Label>
                        <Textarea id="new-description" value={description} onChange={(e) => setDescription(e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="new-features">Features</Label>
                        <Textarea id="new-features" rows={4} value={features} onChange={(e) => setFeatures(e.target.value)} />
                        <p className="text-sm text-muted-foreground">One per line.</p>
                    </div>
                    <TextField
                        label="Free trial (days)"
                        type="number"
                        min={0}
                        value={trial}
                        onChange={(e) => setTrial(e.target.value)}
                        error={fieldError('trial_days')}
                    />
                </>
            )}
        </FormDialog>
    );
}

export function PlansPage() {
    const list = useListState({ sort: 'sort_order', direction: 'asc' });
    const { data, isLoading, error, refetch } = usePlans(list.params);
    const { data: provider } = useMyProvider();
    const { publish, archive, remove } = usePlanMutations();
    const [creating, setCreating] = useState(false);
    const [editingId, setEditingId] = useState<string | null>(null);
    const [archiving, setArchiving] = useState<Plan | null>(null);
    const [deleting, setDeleting] = useState<Plan | null>(null);
    const [actionError, setActionError] = useState<string>();

    async function goLive(plan: Plan) {
        setActionError(undefined);
        try {
            await publish.mutateAsync(plan.id);
            toast.success(`${plan.name} is live`);
        } catch (e) {
            setActionError(message(e));
        }
    }

    const columns: Column<Plan>[] = [
        {
            id: 'name',
            header: 'Plan',
            sortKey: 'name',
            cell: (p) => (
                <>
                    <span className="block font-medium">{p.name}</span>
                    {p.description && <span className="block max-w-xs truncate text-xs text-muted-foreground">{p.description}</span>}
                </>
            ),
        },
        { id: 'status', header: 'Status', sortKey: 'status', cell: (p) => <StatusBadge label={planStatusLabels[p.status]} tone={tones[p.status]} /> },
        {
            id: 'prices',
            header: 'Prices',
            cell: (p) =>
                p.prices.length ? (
                    p.prices.map((price) => (
                        <span key={price.id} className="block text-sm">
                            {formatPrice(price)}
                        </span>
                    ))
                ) : (
                    <span className="text-sm text-muted-foreground">No price</span>
                ),
        },
        { id: 'trial', header: 'Trial', cell: (p) => (p.trial_days ? `${p.trial_days} days` : '—') },
        {
            id: 'actions',
            header: '',
            className: 'text-right',
            cell: (p) => (
                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" aria-label={`Edit ${p.name}`} onClick={() => setEditingId(p.id)}>
                        <Pencil aria-hidden /> Edit
                    </Button>
                    {p.status === 'draft' && (
                        <>
                            <Button size="sm" aria-label={`Publish ${p.name}`} onClick={() => goLive(p)}>
                                <Rocket aria-hidden /> Publish
                            </Button>
                            <Button variant="outline" size="sm" aria-label={`Delete ${p.name}`} onClick={() => setDeleting(p)}>
                                <Trash2 aria-hidden /> Delete
                            </Button>
                        </>
                    )}
                    {p.status === 'active' && (
                        <Button variant="outline" size="sm" aria-label={`Archive ${p.name}`} onClick={() => setArchiving(p)}>
                            <Archive aria-hidden /> Archive
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                title="Plans"
                description="What customers can subscribe to."
                actions={
                    <Button onClick={() => setCreating(true)}>
                        <Plus aria-hidden /> New plan
                    </Button>
                }
            />

            {actionError && (
                <div className="mb-4">
                    <Alert>{actionError}</Alert>
                </div>
            )}

            <div className="mb-4">
                <FilterSelect
                    label="Status"
                    value={list.state.filters.status ?? ''}
                    options={(Object.keys(planStatusLabels) as PlanStatus[]).map((s) => ({ value: s, label: planStatusLabels[s] }))}
                    allLabel="All plans"
                    onChange={(value) => list.setFilter('status', value)}
                />
            </div>

            <DataTable
                columns={columns}
                rows={data?.data}
                meta={data?.meta}
                rowKey={(p) => p.id}
                isLoading={isLoading}
                error={error ? message(error) : undefined}
                onRetry={() => refetch()}
                sort={list.state.sort}
                direction={list.state.direction}
                onSortChange={list.setSort}
                onPageChange={list.setPage}
                emptyTitle="No plans yet"
                emptyHint="Create your first plan to start selling subscriptions."
            />

            <NewPlanDialog open={creating} onOpenChange={setCreating} />
            <PlanEditorDialog plan={data?.data.find((p) => p.id === editingId) ?? null} provider={provider} onClose={() => setEditingId(null)} />

            <ConfirmDialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
                title={`Archive ${archiving?.name ?? ''}?`}
                description="No one new can subscribe to it. Existing subscribers keep their subscription and keep being billed."
                confirmLabel="Archive"
                destructive
                onConfirm={async () => {
                    await archive.mutateAsync(archiving!.id);
                    toast.success(`${archiving!.name} archived`);
                }}
            />
            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Delete ${deleting?.name ?? ''}?`}
                description="This draft is removed. Plans that have been live can only be archived."
                confirmLabel="Delete"
                destructive
                onConfirm={async () => {
                    await remove.mutateAsync(deleting!.id);
                    toast.success('Draft deleted');
                }}
            />
        </>
    );
}
