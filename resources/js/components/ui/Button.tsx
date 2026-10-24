import type { ButtonHTMLAttributes } from 'react';

type Props = ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'quiet'; loading?: boolean };

export function Button({ variant = 'primary', loading, disabled, className = '', children, ...rest }: Props) {
    const styles =
        variant === 'primary'
            ? 'bg-accent text-white hover:bg-accent-strong'
            : 'bg-transparent text-ink hover:bg-canvas border border-line';

    return (
        <button
            {...rest}
            disabled={disabled || loading}
            aria-busy={loading || undefined}
            className={`inline-flex h-11 items-center justify-center rounded-md px-5 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${styles} ${className}`}
        >
            {children}
        </button>
    );
}
