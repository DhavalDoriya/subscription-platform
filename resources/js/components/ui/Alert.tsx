import type { ReactNode } from 'react';

export function Alert({ tone = 'error', children }: { tone?: 'error' | 'success'; children: ReactNode }) {
    const styles = tone === 'error' ? 'border-danger/30 bg-danger/5 text-danger' : 'border-success/30 bg-success/5 text-success';

    return (
        <p role={tone === 'error' ? 'alert' : 'status'} className={`rounded-md border px-3 py-2 text-sm ${styles}`}>
            {children}
        </p>
    );
}
