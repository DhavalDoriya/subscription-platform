import { StatusBadge } from '@/components/StatusBadge';
import { providerStatuses, type ProviderStatus } from './services/providers';

const tones = { pending: 'warn', approved: 'neutral', active: 'good', inactive: 'neutral', suspended: 'bad', rejected: 'bad' } as const;

export function ProviderStatusBadge({ status }: { status: ProviderStatus }) {
    return <StatusBadge label={providerStatuses.find((s) => s.value === status)?.label ?? status} tone={tones[status]} />;
}
