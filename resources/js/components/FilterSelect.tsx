import { cn } from '@/lib/utils';

export interface Option {
    value: string;
    label: string;
}

/** A labelled native select: accessible by default and consistent with the inputs. */
export function FilterSelect({
    label,
    value,
    options,
    onChange,
    allLabel = 'All',
    className,
}: {
    label: string;
    value: string;
    options: Option[];
    onChange: (value: string) => void;
    allLabel?: string;
    className?: string;
}) {
    return (
        <label className={cn('flex items-center gap-2 text-sm', className)}>
            <span className="text-muted-foreground">{label}</span>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="h-8 rounded-lg border border-input bg-card px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
            >
                <option value="">{allLabel}</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </label>
    );
}
