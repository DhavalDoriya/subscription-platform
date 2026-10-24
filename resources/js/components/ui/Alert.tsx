import type { ReactNode } from 'react';

export function Alert({ tone = 'error', children }: { tone?: 'error' | 'success'; children: ReactNode }) {
    const styles = tone === 'error' ? 'border-destructive/30 bg-destructive/5 text-destructive' : 'border-success/30 bg-success/5 text-success';

    return (
        <p role={tone === 'error' ? 'alert' : 'status'} className={`rounded-md border px-3 py-2 text-sm ${styles}`}>
            {children}
        </p>
    );
}
