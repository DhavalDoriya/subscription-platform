import { InvoiceStatusBadge } from '@/components/billing/StatusBadges';
import { Alert } from '@/components/ui/Alert';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { useMyInvoices } from './services/portal';

/** My invoices across providers, newest first, with the Stripe-hosted copy and PDF (FR-CP2). */
export function BillingHistory() {
    const { data, isLoading, error } = useMyInvoices({ per_page: 50 });

    if (isLoading) return <Skeleton className="h-24 w-full" />;
    if (error) return <Alert>{error instanceof ApiError ? error.message : 'Something went wrong.'}</Alert>;

    const invoices = data?.data ?? [];

    if (invoices.length === 0) return <p className="rounded-lg border border-dashed p-6 text-center text-muted-foreground">No invoices yet.</p>;

    return (
        <div className="rounded-lg border bg-card">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Invoice</TableHead>
                        <TableHead>Date</TableHead>
                        <TableHead>Provider</TableHead>
                        <TableHead>Amount</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {invoices.map((i) => (
                        <TableRow key={i.id}>
                            <TableCell className="font-medium">{i.number ?? '—'}</TableCell>
                            <TableCell>{formatDate(i.created_at)}</TableCell>
                            <TableCell>{i.provider?.name}</TableCell>
                            <TableCell>{formatMoney(i.amount_due, i.currency)}</TableCell>
                            <TableCell>
                                <InvoiceStatusBadge status={i.status} />
                            </TableCell>
                            <TableCell className="space-x-3 text-right text-sm">
                                {i.hosted_invoice_url && (
                                    <a
                                        href={i.hosted_invoice_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        aria-label={`View ${i.number}`}
                                        className="text-primary hover:underline"
                                    >
                                        View
                                    </a>
                                )}
                                {i.invoice_pdf_url && (
                                    <a
                                        href={i.invoice_pdf_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        aria-label={`PDF ${i.number}`}
                                        className="text-primary hover:underline"
                                    >
                                        PDF
                                    </a>
                                )}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
