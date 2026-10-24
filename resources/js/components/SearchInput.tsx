import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';

/** Search box that reports the value only after typing pauses, so each keystroke is not a request. */
export function SearchInput({ label, onSearch, delay = 300 }: { label: string; onSearch: (value: string) => void; delay?: number }) {
    const [value, setValue] = useState('');

    useEffect(() => {
        const timer = setTimeout(() => onSearch(value.trim()), delay);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- onSearch identity changes every render; only the typed value should restart the timer
    }, [value, delay]);

    return (
        <div className="relative w-full max-w-xs">
            <Search aria-hidden className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input type="search" aria-label={label} placeholder="Search…" className="pl-8" value={value} onChange={(e) => setValue(e.target.value)} />
        </div>
    );
}
