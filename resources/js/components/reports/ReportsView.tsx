import { useQuery } from '@tanstack/react-query';
import { Download } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { PageHeader } from '@/components/PageHeader';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { api, ApiError } from '@/lib/api';
import { downloadFile } from '@/lib/download';
import { formatMoney } from '@/lib/money';

export interface RevenueRow {
    period: string;
    currency: string;
    payments: number;
    gross: number;
    refunded: number;
    net_revenue: number;
    commission: number;
}
export interface ChurnRow {
    period: string;
    new: number;
    canceled: number;
}
export interface FailureRow {
    reason: string;
    count: number;
    amount: number;
}

type Group = 'month' | 'day';
const exportTypes = [
    ['revenue', 'Revenue'],
    ['payments', 'Payments'],
    ['commission', 'Commission'],
    ['failures', 'Failed payments'],
    ['churn', 'Subscribers'],
] as const;

const message = (e: unknown) => (e instanceof ApiError ? e.message : 'Something went wrong.');

function Table({ caption, head, rows, empty }: { caption: string; head: string[]; rows: (string | number)[][]; empty: string }) {
    return (
        <section className="mb-8">
            <h2 className="mb-2 text-base font-semibold">{caption}</h2>
            <div className="overflow-x-auto rounded-lg border bg-card">
                <table className="w-full text-sm">
                    <caption className="sr-only">{caption}</caption>
                    <thead className="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            {head.map((h) => (
                                <th key={h} scope="col" className="px-4 py-2 font-medium">
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={head.length} className="px-4 py-6 text-center text-muted-foreground">
                                    {empty}
                                </td>
                            </tr>
                        )}
                        {rows.map((row) => (
                            <tr key={row.join('|')} className="border-t">
                                {row.map((cell, i) => (
                                    <td key={i} className="px-4 py-2">
                                        {cell}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

/** Revenue, subscriber and failed-payment reports with CSV export (FR-AD13). `base` is `/admin/reports` or `/provider/reports`. */
export function ReportsView({ base, title, description }: { base: string; title: string; description: string }) {
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [group, setGroup] = useState<Group>('month');
    const params = { from: from || undefined, to: to || undefined };

    const revenue = useQuery({
        queryKey: [base, 'revenue', from, to, group],
        queryFn: () => api.get<RevenueRow[]>(`${base}/revenue`, { params: { ...params, group } }),
    });
    const churn = useQuery({ queryKey: [base, 'churn', from, to], queryFn: () => api.get<ChurnRow[]>(`${base}/churn`, { params }) });
    const failures = useQuery({ queryKey: [base, 'failures', from, to], queryFn: () => api.get<FailureRow[]>(`${base}/failures`, { params }) });
    const error = revenue.error ?? churn.error ?? failures.error;

    async function exportCsv(type: string) {
        try {
            await downloadFile(`${base}/export`, { ...params, type, group }, `${type}.csv`);
        } catch (e) {
            toast.error(message(e));
        }
    }

    return (
        <>
            <PageHeader title={title} description={description} />

            <div className="mb-6 flex flex-wrap items-end gap-4">
                <label className="flex flex-col gap-1 text-sm">
                    <span className="text-muted-foreground">From</span>
                    <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="h-9 rounded-lg border border-input bg-card px-2.5" />
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    <span className="text-muted-foreground">To</span>
                    <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="h-9 rounded-lg border border-input bg-card px-2.5" />
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    <span className="text-muted-foreground">Group revenue by</span>
                    <select value={group} onChange={(e) => setGroup(e.target.value as Group)} className="h-9 rounded-lg border border-input bg-card px-2.5">
                        <option value="month">Month</option>
                        <option value="day">Day</option>
                    </select>
                </label>
                <div className="flex flex-wrap gap-2" role="group" aria-label="Export">
                    {exportTypes.map(([type, label]) => (
                        <Button key={type} variant="outline" size="sm" onClick={() => exportCsv(type)}>
                            <Download aria-hidden /> {label} CSV
                        </Button>
                    ))}
                </div>
            </div>

            {error && <Alert>{message(error)}</Alert>}
            {!error && !revenue.data && <Skeleton className="h-40 w-full" />}
            {revenue.data && (
                <Table
                    caption="Revenue"
                    head={['Period', 'Currency', 'Payments', 'Gross', 'Refunded', 'Net revenue', 'Platform fee']}
                    rows={revenue.data.map((r) => [
                        r.period,
                        r.currency.toUpperCase(),
                        r.payments,
                        formatMoney(r.gross, r.currency),
                        formatMoney(r.refunded, r.currency),
                        formatMoney(r.net_revenue, r.currency),
                        formatMoney(r.commission, r.currency),
                    ])}
                    empty="No payments in this period."
                />
            )}
            {churn.data && (
                <Table
                    caption="Subscribers"
                    head={['Month', 'New', 'Canceled']}
                    rows={churn.data.map((r) => [r.period, r.new, r.canceled])}
                    empty="No subscriber changes in this period."
                />
            )}
            {failures.data && (
                <Table
                    caption="Failed payments"
                    head={['Reason', 'Count']}
                    rows={failures.data.map((r) => [r.reason.replaceAll('_', ' '), r.count])}
                    empty="No failed payments in this period."
                />
            )}
        </>
    );
}
