import type { ReactNode } from 'react';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useFormSubmit } from '@/lib/useFormSubmit';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: string;
    submitLabel: string;
    destructive?: boolean;
    /** Runs on submit; throw an ApiError to show its message (field errors reach `fieldError`). */
    submit: () => Promise<unknown>;
    onSuccess?: () => void;
    children: (form: { fieldError: (field: string) => string | undefined }) => ReactNode;
}

function Body({ onOpenChange, description, submitLabel, destructive, submit, onSuccess, children, title }: Omit<Props, 'open'>) {
    const form = useFormSubmit(submit, () => {
        onOpenChange(false);
        onSuccess?.();
    });

    return (
        <form onSubmit={form.submit} noValidate className="grid gap-4">
            <DialogHeader>
                <DialogTitle>{title}</DialogTitle>
                {description && <DialogDescription>{description}</DialogDescription>}
            </DialogHeader>
            {form.formError && <Alert>{form.formError}</Alert>}
            {children({ fieldError: form.fieldError })}
            <DialogFooter>
                <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                    Cancel
                </Button>
                <Button type="submit" variant={destructive ? 'destructive' : 'default'} loading={form.pending}>
                    {submitLabel}
                </Button>
            </DialogFooter>
        </form>
    );
}

/** The one create/edit dialog shape used across the admin panel. Its body mounts only while open, so errors reset on reopen. */
export function FormDialog({ open, onOpenChange, ...rest }: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>{open && <Body onOpenChange={onOpenChange} {...rest} />}</DialogContent>
        </Dialog>
    );
}
