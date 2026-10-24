import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { DataTable, type Column } from '@/components/data-table/DataTable';
import { FormDialog } from '@/components/FormDialog';
import { PageHeader } from '@/components/PageHeader';
import { StatusBadge } from '@/components/StatusBadge';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/TextField';
import { ApiError } from '@/lib/api';
import { useAuthStore, type AuthUser } from '@/lib/auth';
import { useListState } from '@/lib/useListState';
import { useAddAdmin, useAdmins, useRemoveAdmin } from './services/admins';

function AddAdminDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const add = useAddAdmin();
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');

    return (
        <FormDialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);
                if (!next) {
                    setName('');
                    setEmail('');
                    setPassword('');
                }
            }}
            title="Add admin"
            description="They can sign in right away with this password and manage providers and customers."
            submitLabel="Add admin"
            submit={() => add.mutateAsync({ name, email, password })}
            onSuccess={() => toast.success('Admin added')}
        >
            {({ fieldError }) => (
                <>
                    <TextField label="Name" value={name} onChange={(e) => setName(e.target.value)} error={fieldError('name')} />
                    <TextField label="Email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} error={fieldError('email')} />
                    <TextField
                        label="Password"
                        type="password"
                        autoComplete="new-password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        error={fieldError('password')}
                    />
                </>
            )}
        </FormDialog>
    );
}

export function AdminsPage() {
    const me = useAuthStore((s) => s.user);
    const list = useListState({ sort: 'created_at', direction: 'desc' });
    const { data, isLoading, error, refetch } = useAdmins(list.params);
    const remove = useRemoveAdmin();
    const [adding, setAdding] = useState(false);
    const [removing, setRemoving] = useState<AuthUser | null>(null);

    const columns: Column<AuthUser>[] = [
        {
            id: 'name',
            header: 'Admin',
            sortKey: 'name',
            cell: (a) => (
                <>
                    <span className="block font-medium">{a.name}</span>
                    <span className="block text-xs text-muted-foreground">{a.email}</span>
                </>
            ),
        },
        {
            id: 'role',
            header: 'Role',
            cell: (a) => (a.roles.includes('super_admin') ? <StatusBadge label="Super admin" tone="good" /> : <StatusBadge label="Admin" tone="neutral" />),
        },
        {
            id: 'actions',
            header: '',
            className: 'text-right',
            cell: (a) =>
                a.id === me?.id || a.roles.includes('super_admin') ? null : (
                    <Button variant="outline" size="sm" aria-label={`Remove ${a.name}`} onClick={() => setRemoving(a)}>
                        <Trash2 aria-hidden /> Remove
                    </Button>
                ),
        },
    ];

    return (
        <>
            <PageHeader
                title="Admins"
                description="People who can run the platform. Only super admins see this page."
                actions={
                    <Button onClick={() => setAdding(true)}>
                        <Plus aria-hidden /> Add admin
                    </Button>
                }
            />

            <DataTable
                columns={columns}
                rows={data?.data}
                meta={data?.meta}
                rowKey={(a) => a.id}
                isLoading={isLoading}
                error={error ? (error instanceof ApiError ? error.message : 'Something went wrong.') : undefined}
                onRetry={() => refetch()}
                sort={list.state.sort}
                direction={list.state.direction}
                onSortChange={list.setSort}
                onPageChange={list.setPage}
                emptyTitle="No admins"
            />

            <AddAdminDialog open={adding} onOpenChange={setAdding} />

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={`Remove ${removing?.name ?? ''}?`}
                description="They are signed out everywhere and lose access to the admin panel. This is recorded in the audit log."
                confirmLabel="Remove"
                destructive
                onConfirm={async () => {
                    await remove.mutateAsync(removing!.id);
                    toast.success(`${removing!.name} removed`);
                }}
            />
        </>
    );
}
