import { TriangleAlert } from 'lucide-react';
import { Link } from 'react-router-dom';
import { formatMoney } from '@/lib/money';
import { usePaymentIssues } from './services/portal';

/** A warning at the top of the account when a payment failed (FR-R3). Renders nothing while everything is fine. */
export function PaymentIssues() {
    const { data } = usePaymentIssues();

    if (!data?.length) return null;

    return (
        <section aria-label="Payment problems" className="mt-8 rounded-lg border border-destructive/30 bg-destructive/5 p-4">
            <h2 className="flex items-center gap-2 font-semibold text-destructive">
                <TriangleAlert aria-hidden className="size-4" /> We could not collect a payment
            </h2>
            <ul className="mt-2 grid gap-1 text-sm">
                {data.map((issue) => (
                    <li key={issue.invoice_id}>
                        {issue.plan.name} with {issue.provider.name}: {formatMoney(issue.amount_due, issue.currency)} is overdue.
                    </li>
                ))}
            </ul>
            <Link to="/account/fix-payment" className="mt-3 inline-block text-sm font-medium text-primary hover:underline">
                Fix payment
            </Link>
        </section>
    );
}
