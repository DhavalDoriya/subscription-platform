import { useState } from 'react';
import { useMedia, type Media } from '@/areas/provider/services/media';
import { SearchInput } from '@/components/SearchInput';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSelect: (media: Media) => void;
}

/** Grid of the provider's uploaded images; picking one reports it and the caller closes the dialog. */
export function MediaLibraryDialog({ open, onOpenChange, onSelect }: Props) {
    const [search, setSearch] = useState('');
    const { data, isPending, isError } = useMedia({ per_page: 24, search: search || undefined }, open);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Media library</DialogTitle>
                    <DialogDescription>Choose one of your uploaded images.</DialogDescription>
                </DialogHeader>
                <SearchInput label="Search images" onSearch={setSearch} />
                {isError ? (
                    <p role="alert" className="text-sm text-destructive">
                        Could not load your images.
                    </p>
                ) : isPending ? (
                    <p className="text-sm text-muted-foreground">Loading…</p>
                ) : data.data.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        {search ? 'No images match your search.' : 'No images yet. Upload one to see it here.'}
                    </p>
                ) : (
                    <ul className="grid max-h-96 grid-cols-2 gap-3 overflow-y-auto sm:grid-cols-3">
                        {data.data.map((media) => (
                            <li key={media.id}>
                                <button
                                    type="button"
                                    aria-label={media.original_name}
                                    onClick={() => onSelect(media)}
                                    className="group grid w-full gap-1 rounded-md border p-1.5 text-left outline-none transition-colors hover:border-primary focus-visible:border-primary"
                                >
                                    <img src={media.url} alt="" className="aspect-video w-full rounded object-cover" />
                                    <span className="truncate px-1 text-xs text-muted-foreground">{media.original_name}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </DialogContent>
        </Dialog>
    );
}
