import { Eye, Ban, ShieldCheck, UserRoundCog } from 'lucide-react';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { DataTable, type Column } from '@/components/data-table/DataTable';
import { FilterSelect } from '@/components/FilterSelect';
import { PageHeader } from '@/components/PageHeader';
import { SearchInput } from '@/components/SearchInput';
import { StatusBadge } from '@/components/StatusBadge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ApiError } from '@/lib/api';
import { impersonate } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import { useListState } from '@/lib/useListState';
import { useBlockCustomer, useCustomer, useCustomers, type Customer } from './services/customers';

const message = (e: unknown) => (e instanceof ApiError ? e.message : 'Something went wrong.');

function CustomerDetail({ id, onClose }: { id: string | null; onClose: () => void }) {
    const { data, isLoading, error } = useCustomer(id);

    return (
        <Dialog open={id !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{data?.name ?? 'Customer'}</DialogTitle>
                    <DialogDescription>{data?.email}</DialogDescription>
                </DialogHeader>
                {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}
                {error && (
                    <p role="alert" className="text-sm text-destructive">
                        {message(error)}
                    </p>
                )}
                {data && (
                    <div>
                        <h3 className="mb-2 text-sm font-medium">Subscribed to</h3>
                        {data.providers?.length ? (
                            <ul className="divide-y rounded-md border">
                                {data.providers.map((p) => (
                                    <li key={p.id} className="flex items-center justify-between px-3 py-2 text-sm">
                                        <span>{p.name}</span>
                                        <span className="text-muted-foreground">since {formatDate(p.joined_at)}</span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-muted-foreground">Not subscribed to any provider yet.</p>
                        )}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

export function CustomersPage() {
    const list = useListState({ sort: 'created_at', direction: 'desc' });
    const { data, isLoading, error, refetch } = useCustomers(list.params);
    const block = useBlockCustomer();
    const navigate = useNavigate();
    const [viewing, setViewing] = useState<string | null>(null);
    const [blocking, setBlocking] = useState<Customer | null>(null);
    const access = list.state.filters.blocked === '1' ? 'blocked' : list.state.filters.blocked === '0' ? 'active' : '';

    async function unblock(customer: Customer) {
        try {
            await block.mutateAsync({ id: customer.id, blocked: false });
            toast.success(`${customer.name} unblocked`);
        } catch (e) {
            toast.error(message(e));
        }
    }

    async function actAs(customer: Customer) {
        try {
            await impersonate(customer.id);
            navigate('/account');
        } catch (e) {
            toast.error(message(e));
        }
    }

    const columns: Column<Customer>[] = [
        {
            id: 'name',
            header: 'Customer',
            sortKey: 'name',
            cell: (c) => (
                <>
                    <span className="block font-medium">{c.name}</span>
                    <span className="block text-xs text-muted-foreground">{c.email}</span>
                </>
            ),
        },
        { id: 'status', header: 'Access', cell: (c) => <StatusBadge label={c.is_blocked ? 'Blocked' : 'Active'} tone={c.is_blocked ? 'bad' : 'good'} /> },
        { id: 'created', header: 'Joined', sortKey: 'created_at', cell: (c) => formatDate(c.created_at) },
        {
            id: 'actions',
            header: '',
            className: 'text-right',
            cell: (c) => (
                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" aria-label={`View ${c.name}`} onClick={() => setViewing(c.id)}>
                        <Eye aria-hidden /> View
                    </Button>
                    {c.is_blocked ? (
                        <Button variant="outline" size="sm" aria-label={`Unblock ${c.name}`} onClick={() => unblock(c)}>
                            <ShieldCheck aria-hidden /> Unblock
                        </Button>
                    ) : (
                        <>
                            <Button variant="outline" size="sm" aria-label={`Impersonate ${c.name}`} onClick={() => actAs(c)}>
                                <UserRoundCog aria-hidden /> Impersonate
                            </Button>
                            <Button variant="outline" size="sm" aria-label={`Block ${c.name}`} onClick={() => setBlocking(c)}>
                                <Ban aria-hidden /> Block
                            </Button>
                        </>
                    )}
                </div>
            ),
        },
    ];

    return (
        <>
            <PageHeader title="Customers" description="People with an account who can subscribe to providers." />

            <div className="mb-4 flex flex-wrap items-center gap-4">
                <SearchInput label="Search customers" onSearch={list.setSearch} />
                <FilterSelect
                    label="Access"
                    value={access}
                    options={[
                        { value: 'active', label: 'Active' },
                        { value: 'blocked', label: 'Blocked' },
                    ]}
                    allLabel="Everyone"
                    onChange={(value) => list.setFilter('blocked', value === 'blocked' ? '1' : value === 'active' ? '0' : '')}
                />
            </div>

            <DataTable
                columns={columns}
                rows={data?.data}
                meta={data?.meta}
                rowKey={(c) => c.id}
                isLoading={isLoading}
                error={error ? message(error) : undefined}
                onRetry={() => refetch()}
                sort={list.state.sort}
                direction={list.state.direction}
                onSortChange={list.setSort}
                onPageChange={list.setPage}
                emptyTitle="No customers found"
                emptyHint="Customers appear here once they register."
            />

            <CustomerDetail id={viewing} onClose={() => setViewing(null)} />

            <ConfirmDialog
                open={blocking !== null}
                onOpenChange={(open) => !open && setBlocking(null)}
                title={`Block ${blocking?.name ?? ''}?`}
                description={`${blocking?.name ?? 'This customer'} will be signed out everywhere and cannot sign in until you unblock them.`}
                confirmLabel="Block"
                destructive
                onConfirm={async () => {
                    await block.mutateAsync({ id: blocking!.id, blocked: true });
                    toast.success(`${blocking!.name} blocked`);
                }}
            />
        </>
    );
}
