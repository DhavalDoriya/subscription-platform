import { useState } from 'react';
import { toast } from 'sonner';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { TextField } from '@/components/ui/TextField';
import { ApiError } from '@/lib/api';
import { formatPrice, toMajorString, toMinor } from '@/lib/money';
import { useFormSubmit } from '@/lib/useFormSubmit';
import { usePlanMutations, type Plan, type Price } from './services/plans';
import { usePlatformConfig, type MyProvider } from './services/provider';

function DetailsForm({ plan }: { plan: Plan }) {
    const { update } = usePlanMutations();
    const [name, setName] = useState(plan.name);
    const [description, setDescription] = useState(plan.description ?? '');
    const [features, setFeatures] = useState(plan.features.join('\n'));
    const [trial, setTrial] = useState(String(plan.trial_days));
    const form = useFormSubmit(
        () =>
            update.mutateAsync({
                id: plan.id,
                name,
                description: description || null,
                features: features
                    .split('\n')
                    .map((f) => f.trim())
                    .filter(Boolean),
                trial_days: Number(trial) || 0,
                visibility: plan.visibility,
            }),
        () => toast.success('Plan saved'),
    );

    return (
        <form onSubmit={form.submit} noValidate className="grid gap-4">
            {form.formError && <Alert>{form.formError}</Alert>}
            <TextField label="Name" value={name} onChange={(e) => setName(e.target.value)} error={form.fieldError('name')} />
            <div className="grid gap-1.5">
                <Label htmlFor="edit-description">Description</Label>
                <Textarea id="edit-description" value={description} onChange={(e) => setDescription(e.target.value)} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="edit-features">Features</Label>
                <Textarea id="edit-features" rows={4} value={features} onChange={(e) => setFeatures(e.target.value)} />
                <p className="text-sm text-muted-foreground">One per line.</p>
            </div>
            <TextField
                label="Free trial (days)"
                type="number"
                min={0}
                value={trial}
                onChange={(e) => setTrial(e.target.value)}
                error={form.fieldError('trial_days')}
            />
            <div>
                <Button type="submit" loading={form.pending}>
                    Save plan
                </Button>
            </div>
        </form>
    );
}

function PriceForm({ plan, currency, editing, onDone }: { plan: Plan; currency: string; editing: Price | null; onDone: () => void }) {
    const { data: config } = usePlatformConfig();
    const { addPrice, changePrice } = usePlanMutations();
    const [amount, setAmount] = useState(editing ? toMajorString(editing.amount, currency) : '');
    const [interval, setInterval] = useState(editing?.interval ?? 'month');
    const [amountError, setAmountError] = useState<string>();
    const intervals = config?.allowed_intervals ?? ['month', 'year'];
    const form = useFormSubmit(
        async () => {
            const minor = toMinor(amount, currency);
            if (minor === null) {
                setAmountError('Enter a valid amount, for example 19.00.');
                throw new ApiError('invalid', 0);
            }
            setAmountError(undefined);
            const input = { amount: minor, currency, interval, interval_count: 1 };
            return editing ? changePrice.mutateAsync({ planId: plan.id, priceId: editing.id, ...input }) : addPrice.mutateAsync({ planId: plan.id, ...input });
        },
        () => {
            setAmount('');
            onDone();
        },
    );

    return (
        <form onSubmit={form.submit} noValidate className="grid gap-3 rounded-md border p-4">
            <h4 className="text-sm font-medium">{editing ? `Change ${formatPrice(editing)}` : 'Add a price'}</h4>
            {form.formError && form.formError !== 'invalid' && <Alert>{form.formError}</Alert>}
            <TextField
                label={`Amount (${currency.toUpperCase()})`}
                inputMode="decimal"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                error={amountError ?? form.fieldError('amount')}
            />
            <div className="grid gap-1.5">
                <Label htmlFor="price-interval">Billed every</Label>
                <select
                    id="price-interval"
                    value={interval}
                    onChange={(e) => setInterval(e.target.value)}
                    className="h-10 rounded-md border border-input bg-card px-3 text-sm"
                >
                    {intervals.map((i) => (
                        <option key={i} value={i}>
                            {i}
                        </option>
                    ))}
                </select>
                {form.fieldError('interval') && <p className="text-sm text-destructive">{form.fieldError('interval')}</p>}
            </div>
            <div className="flex gap-2">
                <Button type="submit" loading={form.pending}>
                    {editing ? 'Save price' : 'Add price'}
                </Button>
                {editing && (
                    <Button type="button" variant="outline" onClick={onDone}>
                        Cancel
                    </Button>
                )}
            </div>
        </form>
    );
}

function PricesSection({ plan, provider }: { plan: Plan; provider: MyProvider | undefined }) {
    const { removePrice } = usePlanMutations();
    const [editing, setEditing] = useState<Price | null>(null);
    const [error, setError] = useState<string>();
    const currency = provider?.currency ?? plan.prices[0]?.currency ?? 'usd';

    async function remove(price: Price) {
        setError(undefined);
        try {
            await removePrice.mutateAsync({ planId: plan.id, priceId: price.id });
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'Something went wrong.');
        }
    }

    return (
        <section aria-label="Prices" className="grid gap-3 border-t pt-4">
            <h3 className="text-sm font-medium">Prices</h3>
            {plan.status === 'active' && (
                <p className="text-sm text-muted-foreground">
                    Live prices cannot be edited on Stripe. Changing one replaces the price with a new one and retires the old one for new sign-ups.
                </p>
            )}
            {error && <Alert>{error}</Alert>}
            {plan.prices.length === 0 ? (
                <p className="text-sm text-muted-foreground">No prices yet. A plan needs at least one before it can go live.</p>
            ) : (
                <ul className="divide-y rounded-md border">
                    {plan.prices.map((price) => (
                        <li key={price.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                            <span>{formatPrice(price)}</span>
                            <span className="flex gap-2">
                                <Button size="sm" variant="outline" aria-label={`Change ${formatPrice(price)}`} onClick={() => setEditing(price)}>
                                    Change
                                </Button>
                                <Button size="sm" variant="outline" aria-label={`Remove ${formatPrice(price)}`} onClick={() => remove(price)}>
                                    Remove
                                </Button>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            <PriceForm key={editing?.id ?? 'new'} plan={plan} currency={currency} editing={editing} onDone={() => setEditing(null)} />
        </section>
    );
}

export function PlanEditorDialog({ plan, provider, onClose }: { plan: Plan | null; provider: MyProvider | undefined; onClose: () => void }) {
    if (!plan) return null;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Edit {plan.name}</DialogTitle>
                    <DialogDescription>
                        {plan.status === 'active'
                            ? 'This plan is live. Name and description changes also update Stripe.'
                            : 'Drafts stay private until you publish them.'}
                    </DialogDescription>
                </DialogHeader>
                <DetailsForm key={plan.id} plan={plan} />
                <PricesSection plan={plan} provider={provider} />
            </DialogContent>
        </Dialog>
    );
}
