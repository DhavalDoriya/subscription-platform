import { useId, type TextareaHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

type Props = TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; error?: string; hint?: string };

/** A labelled textarea with the same error and hint wiring as TextField. */
export function TextAreaField({ label, error, hint, id, className, ...rest }: Props) {
    const auto = useId();
    const fieldId = id ?? auto;
    const describedBy = error ? `${fieldId}-error` : hint ? `${fieldId}-hint` : undefined;

    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={fieldId} className="text-sm font-medium">
                {label}
            </label>
            <textarea
                {...rest}
                id={fieldId}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
                className={cn(
                    'min-h-20 rounded-md border bg-card px-3 py-2 text-base outline-none transition-colors focus:border-primary',
                    error ? 'border-destructive' : 'border-input',
                    className,
                )}
            />
            {error ? (
                <p id={`${fieldId}-error`} className="text-sm text-destructive">
                    {error}
                </p>
            ) : hint ? (
                <p id={`${fieldId}-hint`} className="text-sm text-muted-foreground">
                    {hint}
                </p>
            ) : null}
        </div>
    );
}
