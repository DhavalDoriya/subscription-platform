import { useState } from 'react';
import { toast } from 'sonner';
import { FormDialog } from '@/components/FormDialog';
import { TextField } from '@/components/ui/TextField';
import { ApiError } from '@/lib/api';
import type { Payment } from '@/lib/billing';
import { formatMoney, toMajorString, toMinor } from '@/lib/money';
import { useBillingMutations } from './services/billing';

const reasons = [
    { value: '', label: 'No reason' },
    { value: 'requested_by_customer', label: 'Requested by customer' },
    { value: 'duplicate', label: 'Duplicate' },
    { value: 'fraudulent', label: 'Fraudulent' },
];

/** Full or partial refund (FR-C10). The amount defaults to everything that is left and cannot go above it. */
export function RefundDialog({ payment, onClose }: { payment: Payment | null; onClose: () => void }) {
    const { refund } = useBillingMutations();
    const [amount, setAmount] = useState('');
    const [reason, setReason] = useState('');
    const [openFor, setOpenFor] = useState<string | null>(null);

    // Reset the form each time a different payment is opened.
    if (payment && openFor !== payment.id) {
        setOpenFor(payment.id);
        setAmount(toMajorString(payment.refundable, payment.currency));
        setReason('');
    }

    return (
        <FormDialog
            open={payment !== null}
            onOpenChange={(open) => {
                if (!open) {
                    setOpenFor(null);
                    onClose();
                }
            }}
            title="Refund payment"
            description={
                payment
                    ? `Up to ${formatMoney(payment.refundable, payment.currency)} can still be refunded. The customer is refunded on their original card.`
                    : undefined
            }
            submitLabel="Refund"
            submit={async () => {
                const minor = toMinor(amount, payment!.currency);
                if (minor === null || minor < 1 || minor > payment!.refundable) {
                    throw new ApiError('Invalid amount.', 422, { amount: [`Enter an amount up to ${formatMoney(payment!.refundable, payment!.currency)}.`] });
                }
                return refund.mutateAsync({ id: payment!.id, amount: minor, reason: reason || null });
            }}
            onSuccess={() => toast.success('Refund issued')}
        >
            {({ fieldError }) => (
                <>
                    <TextField label="Amount" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} error={fieldError('amount')} />
                    <label className="grid gap-1.5 text-sm font-medium">
                        Reason
                        <select
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            className="h-11 rounded-md border bg-card px-3 text-base font-normal"
                        >
                            {reasons.map((r) => (
                                <option key={r.value} value={r.value}>
                                    {r.label}
                                </option>
                            ))}
                        </select>
                    </label>
                </>
            )}
        </FormDialog>
    );
}
