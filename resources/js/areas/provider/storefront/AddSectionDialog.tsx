import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { BlockType } from '@/areas/storefront/types';
import { blockDefs, blockTypes } from './blockSchemas';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onPick: (type: BlockType) => void;
}

/** Every section type as a card with a one-line description; picking one adds it to the page. */
export function AddSectionDialog({ open, onOpenChange, onPick }: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Add a section</DialogTitle>
                    <DialogDescription>Choose what to add. You can reorder, hide or remove it later.</DialogDescription>
                </DialogHeader>
                <ul className="grid gap-2 sm:grid-cols-2">
                    {blockTypes.map((type) => (
                        <li key={type}>
                            <button
                                type="button"
                                data-section-type={type}
                                onClick={() => onPick(type)}
                                className="grid h-full w-full gap-0.5 rounded-lg border p-3 text-left outline-none transition-colors hover:border-primary focus-visible:border-primary"
                            >
                                <span className="text-sm font-medium">{blockDefs[type].label}</span>
                                <span className="text-sm text-muted-foreground">{blockDefs[type].description}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            </DialogContent>
        </Dialog>
    );
}
