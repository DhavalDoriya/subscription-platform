import type { ReactNode } from 'react';

/** A failed charge being recovered: the product's core promise, shown as the timeline the retry engine follows. */
function RecoveryTimeline() {
    const steps = [
        { when: 'Day 0', what: 'Card declined: insufficient funds', tone: 'bg-danger' },
        { when: 'Day 1', what: 'Retry scheduled, customer emailed', tone: 'bg-muted' },
        { when: 'Day 3', what: 'Second retry succeeds', tone: 'bg-success' },
        { when: 'Day 3', what: 'Subscription stays active', tone: 'bg-success' },
    ];

    return (
        <ol className="mt-10 space-y-5 border-l border-white/20 pl-6">
            {steps.map((step) => (
                <li key={step.what} className="relative">
                    <span className={`absolute -left-[29px] top-1.5 size-2.5 rounded-full ring-4 ring-ink ${step.tone}`} />
                    <p className="text-sm text-white/60">{step.when}</p>
                    <p className="text-base text-white">{step.what}</p>
                </li>
            ))}
        </ol>
    );
}

export function AuthLayout({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
    return (
        <main className="grid min-h-screen lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
            <aside className="hidden bg-ink p-14 text-white lg:flex lg:flex-col lg:justify-center">
                <h2 className="max-w-md text-4xl font-semibold leading-tight">Failed payments get a second chance, automatically.</h2>
                <p className="mt-4 max-w-md text-white/70">Retries follow the schedule you set, and customers hear from you before anything is cancelled.</p>
                <RecoveryTimeline />
            </aside>

            <section className="flex items-center justify-center px-4 py-12">
                <div className="w-full max-w-sm">
                    <h1 className="text-3xl font-semibold">{title}</h1>
                    {subtitle && <p className="mt-2 text-muted">{subtitle}</p>}
                    <div className="mt-8">{children}</div>
                </div>
            </section>
        </main>
    );
}
