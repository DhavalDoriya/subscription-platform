import { useState } from 'react';
import { Alert } from '@/components/ui/Alert';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/api';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    confirmLabel: string;
    destructive?: boolean;
    onConfirm: () => Promise<unknown>;
}

/** Confirmation for consequential actions. Stays open and shows the message if the action fails. */
export function ConfirmDialog({ open, onOpenChange, title, description, confirmLabel, destructive, onConfirm }: Props) {
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string>();

    async function confirm() {
        setPending(true);
        setError(undefined);
        try {
            await onConfirm();
            onOpenChange(false);
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'Something went wrong.');
        } finally {
            setPending(false);
        }
    }

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                if (!next) setError(undefined);
                onOpenChange(next);
            }}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>{description}</AlertDialogDescription>
                </AlertDialogHeader>
                {error && <Alert>{error}</Alert>}
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <Button variant={destructive ? 'destructive' : 'default'} loading={pending} onClick={confirm}>
                        {confirmLabel}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
