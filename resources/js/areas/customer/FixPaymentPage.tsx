import { Link } from 'react-router-dom';
import { Alert } from '@/components/ui/Alert';
import { Skeleton } from '@/components/ui/skeleton';
import { ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { PaymentMethods } from './PaymentMethods';
import { usePaymentIssues, type PaymentIssue } from './services/portal';

function Explanation({ issue }: { issue: PaymentIssue }) {
    const grace = issue.grace_ends_at ? `Your access continues until ${formatDate(issue.grace_ends_at)}.` : null;

    if (issue.decline_type === 'auth_required') {
        return (
            <>
                <p>Your bank needs you to confirm this payment before it can go through.</p>
                {issue.hosted_invoice_url && (
                    <a
                        href={issue.hosted_invoice_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="mt-2 inline-block font-medium text-primary hover:underline"
                    >
                        Confirm payment
                    </a>
                )}
                {grace && <p className="mt-2">{grace}</p>}
            </>
        );
    }

    if (issue.decline_type === 'hard') {
        return (
            <>
                <p>This card was declined and can’t be used again. Add a new card below to keep your subscription.</p>
                {grace && <p className="mt-2">{grace}</p>}
            </>
        );
    }

    return (
        <>
            <p>
                {issue.next_retry_at
                    ? `We’ll try again on ${formatDate(issue.next_retry_at)}. To pay sooner, choose another card below.`
                    : 'We could not collect this payment. Choose another card below to try again now.'}
            </p>
            {grace && <p className="mt-2">{grace}</p>}
        </>
    );
}

/** Where the emails send a customer whose payment failed: what happened, and the cards that can put it right (FR-R3, FR-R8). */
export function FixPaymentPage() {
    const { data, error, isLoading } = usePaymentIssues();

    return (
        <main className="mx-auto max-w-3xl px-4 py-12">
            <Link to="/account" className="text-sm text-primary hover:underline">
                Back to my account
            </Link>
            <h1 className="mt-4 text-3xl font-semibold">Fix your payment</h1>

            {error && (
                <div className="mt-6">
                    <Alert>{error instanceof ApiError ? error.message : 'Something went wrong.'}</Alert>
                </div>
            )}
            {isLoading && <Skeleton className="mt-6 h-24 w-full" />}

            {data?.length === 0 && (
                <p className="mt-6 rounded-lg border border-dashed p-6 text-center text-muted-foreground">Nothing to fix. All your payments are up to date.</p>
            )}

            <ul className="mt-6 grid gap-4">
                {data?.map((issue) => (
                    <li key={issue.invoice_id} className="rounded-lg border bg-card p-4 text-sm">
                        <h2 className="text-base font-semibold">
                            {issue.plan.name} with {issue.provider.name}
                        </h2>
                        <p className="mt-1 font-medium">{formatMoney(issue.amount_due, issue.currency)} overdue</p>
                        {issue.failure_message && <p className="mt-1 text-muted-foreground">{issue.failure_message}</p>}
                        <div className="mt-3">
                            <Explanation issue={issue} />
                        </div>
                    </li>
                ))}
            </ul>

            {data && data.length > 0 && (
                <section aria-label="Payment methods" className="mt-10">
                    <h2 className="mb-4 text-xl font-semibold">Payment methods</h2>
                    <PaymentMethods />
                </section>
            )}
        </main>
    );
}
