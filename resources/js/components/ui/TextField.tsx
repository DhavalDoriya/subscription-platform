import { useId, type InputHTMLAttributes } from 'react';

type Props = InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string; hint?: string };

export function TextField({ label, error, hint, id, ...rest }: Props) {
    const auto = useId();
    const inputId = id ?? auto;
    const describedBy = error ? `${inputId}-error` : hint ? `${inputId}-hint` : undefined;

    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={inputId} className="text-sm font-medium">
                {label}
            </label>
            <input
                {...rest}
                id={inputId}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
                className={`h-11 rounded-md border bg-card px-3 text-base outline-none transition-colors focus:border-primary ${error ? 'border-destructive' : 'border-border'}`}
            />
            {error ? (
                <p id={`${inputId}-error`} className="text-sm text-destructive">
                    {error}
                </p>
            ) : hint ? (
                <p id={`${inputId}-hint`} className="text-sm text-muted-foreground">
                    {hint}
                </p>
            ) : null}
        </div>
    );
}
