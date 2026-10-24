import { Badge } from '@/components/ui/badge';

type Tone = 'good' | 'warn' | 'bad' | 'neutral';

const variants = { good: 'secondary', warn: 'outline', bad: 'destructive', neutral: 'outline' } as const;
const extra: Record<Tone, string> = {
    good: 'bg-success/10 text-success',
    warn: 'border-amber-500/40 bg-amber-50 text-amber-800',
    bad: '',
    neutral: 'text-muted-foreground',
};

export function StatusBadge({ label, tone }: { label: string; tone: Tone }) {
    return (
        <Badge variant={variants[tone]} className={extra[tone]}>
            {label}
        </Badge>
    );
}
