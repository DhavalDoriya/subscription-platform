import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { DataTable, type Column } from '@/components/data-table/DataTable';
import { FilterSelect } from '@/components/FilterSelect';
import { FormDialog } from '@/components/FormDialog';
import { PageHeader } from '@/components/PageHeader';
import { StatusBadge } from '@/components/StatusBadge';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/TextField';
import { ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { useListState } from '@/lib/useListState';
import { useCommissionRuleMutations, useCommissionRules, type CommissionRule } from './services/commission';
import { useProviders } from './services/providers';

const message = (e: unknown) => (e instanceof ApiError ? e.message : 'Something went wrong.');
const providerLabel = 'Provider (leave empty for the default rate)';

function AddRateDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const { add } = useCommissionRuleMutations();
    const providers = useProviders({ per_page: 100, sort: 'name', direction: 'asc' });
    const [providerId, setProviderId] = useState('');
    const [percent, setPercent] = useState('');
    const [effectiveFrom, setEffectiveFrom] = useState('');

    return (
        <FormDialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);
                if (!next) {
                    setProviderId('');
                    setPercent('');
                    setEffectiveFrom('');
                }
            }}
            title="Add a commission rate"
            description="The rate applies to subscriptions sold from the start date on. Existing subscriptions and past payments keep the rate they were sold with."
            submitLabel="Add rate"
            submit={() =>
                add.mutateAsync({
                    percent,
                    ...(providerId ? { provider_id: providerId } : {}),
                    ...(effectiveFrom ? { effective_from: effectiveFrom } : {}),
                })
            }
            onSuccess={() => toast.success('Commission rate added')}
        >
            {({ fieldError }) => (
                <>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor="rate-provider" className="text-sm font-medium">
                            {providerLabel}
                        </label>
                        <select
                            id="rate-provider"
                            value={providerId}
                            onChange={(e) => setProviderId(e.target.value)}
                            className="h-11 rounded-md border border-border bg-card px-3 text-base outline-none focus:border-primary"
                        >
                            <option value="">All providers (default rate)</option>
                            {providers.data?.data.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                        {fieldError('provider_id') && <p className="text-sm text-destructive">{fieldError('provider_id')}</p>}
                    </div>
                    <TextField
                        label="Commission (%)"
                        inputMode="decimal"
                        value={percent}
                        onChange={(e) => setPercent(e.target.value)}
                        hint="0 to 100, up to two decimals."
                        error={fieldError('percent')}
                    />
                    <TextField
                        label="Effective from"
                        type="datetime-local"
                        value={effectiveFrom}
                        onChange={(e) => setEffectiveFrom(e.target.value)}
                        hint="Leave empty to start now."
                        error={fieldError('effective_from')}
                    />
                </>
            )}
        </FormDialog>
    );
}

/** FR-CM1, FR-CM2: the default rate and per-provider overrides, with history. A change is a new rate from a date. */
export function CommissionRatesPage() {
    const list = useListState({ sort: 'effective_from', direction: 'desc' });
    const { data, isLoading, error, refetch } = useCommissionRules(list.params);
    const { remove } = useCommissionRuleMutations();
    const [adding, setAdding] = useState(false);
    const [removing, setRemoving] = useState<CommissionRule | null>(null);

    const columns: Column<CommissionRule>[] = [
        {
            id: 'applies',
            header: 'Applies to',
            cell: (r) => (r.provider ? <span className="font-medium">{r.provider.name}</span> : <span className="font-medium">All providers (default)</span>),
        },
        { id: 'percent', header: 'Commission', sortKey: 'percent', cell: (r) => `${r.percent}%` },
        { id: 'from', header: 'Effective from', sortKey: 'effective_from', cell: (r) => formatDate(r.effective_from), className: 'whitespace-nowrap' },
        { id: 'status', header: 'Status', cell: (r) => <StatusBadge label={r.in_effect ? 'Started' : 'Scheduled'} tone={r.in_effect ? 'good' : 'warn'} /> },
        {
            id: 'actions',
            header: '',
            className: 'text-right',
            cell: (r) =>
                r.in_effect ? null : (
                    <Button
                        variant="outline"
                        size="sm"
                        aria-label={`Remove the ${r.percent}% rate from ${formatDate(r.effective_from)}`}
                        onClick={() => setRemoving(r)}
                    >
                        <Trash2 aria-hidden /> Remove
                    </Button>
                ),
        },
    ];

    return (
        <>
            <PageHeader
                title="Commission rates"
                description="The platform fee taken from each subscription payment. A provider rate beats the default; the newest rate that has started applies."
                actions={
                    <Button onClick={() => setAdding(true)}>
                        <Plus aria-hidden /> Add rate
                    </Button>
                }
            />

            <div className="mb-4 flex flex-wrap items-center gap-4">
                <FilterSelect
                    label="Show"
                    value={list.state.filters.scope ?? ''}
                    options={[{ value: 'global', label: 'Default rates only' }]}
                    allLabel="All rates"
                    onChange={(value) => list.setFilter('scope', value)}
                />
            </div>

            <DataTable
                columns={columns}
                rows={data?.data}
                meta={data?.meta}
                rowKey={(r) => r.id}
                isLoading={isLoading}
                error={error ? message(error) : undefined}
                onRetry={() => refetch()}
                sort={list.state.sort}
                direction={list.state.direction}
                onSortChange={list.setSort}
                onPageChange={list.setPage}
                emptyTitle="No commission set"
                emptyHint="Until you add a rate, providers keep all of every payment."
            />

            <AddRateDialog open={adding} onOpenChange={setAdding} />
            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title="Remove this rate?"
                description="It has not started yet, so no payment has used it."
                confirmLabel="Remove rate"
                destructive
                onConfirm={async () => {
                    await remove.mutateAsync(removing!.id);
                    toast.success('Rate removed');
                }}
            />
        </>
    );
}
