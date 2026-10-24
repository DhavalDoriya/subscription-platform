import { ArrowDown, ArrowUp, ChevronRight, Eye, EyeOff, Plus, Search, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { blockDefs } from './blockSchemas';
import type { EditorBlock } from './editorState';

interface Props {
    blocks: EditorBlock[];
    /** Whether the block at this index has server validation errors. */
    hasError: (index: number) => boolean;
    seoHasError: boolean;
    onSelect: (target: 'seo' | string) => void;
    onMove: (index: number, delta: -1 | 1) => void;
    onToggleVisible: (key: string) => void;
    onRemove: (key: string) => void;
    onAdd: () => void;
}

const Attention = () => <span className="rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-normal text-destructive">Needs attention</span>;

/** The page as a list of sections. Choosing one opens its form; the row buttons reorder, hide or remove it. */
export function SectionList({ blocks, hasError, seoHasError, onSelect, onMove, onToggleVisible, onRemove, onAdd }: Props) {
    return (
        <div className="grid gap-4">
            <button
                type="button"
                aria-label="Edit Search and sharing"
                onClick={() => onSelect('seo')}
                className="flex items-center gap-3 rounded-lg border bg-card px-4 py-3 text-left outline-none transition-colors hover:border-primary focus-visible:border-primary"
            >
                <Search aria-hidden className="size-4 text-muted-foreground" />
                <span className="min-w-0 flex-1">
                    <span className="flex items-center gap-2 text-sm font-medium">Search and sharing {seoHasError && <Attention />}</span>
                    <span className="block text-sm text-muted-foreground">Page title, description and share image</span>
                </span>
                <ChevronRight aria-hidden className="size-4 text-muted-foreground" />
            </button>

            <div className="grid gap-3">
                <h2 className="text-sm font-medium">Sections</h2>
                {blocks.length === 0 && (
                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        No sections yet. Add your first section below.
                    </p>
                )}
                <ul aria-label="Sections" className="grid gap-2">
                    {blocks.map((block, index) => {
                        const def = blockDefs[block.type];
                        return (
                            <li
                                key={block.key}
                                aria-label={`${def.label} block`}
                                className={cn('flex items-center gap-2 rounded-lg border bg-card pr-2', !block.visible && 'opacity-60')}
                            >
                                <button
                                    type="button"
                                    aria-label={`Edit ${def.label}`}
                                    onClick={() => onSelect(block.key)}
                                    className="min-w-0 flex-1 rounded-l-lg px-4 py-3 text-left outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <span className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                        {def.label}
                                        {!block.visible && <span className="text-xs font-normal text-muted-foreground">Hidden</span>}
                                        {hasError(index) && <Attention />}
                                    </span>
                                    <span className="block truncate text-sm text-muted-foreground">{def.summary(block.data)}</span>
                                </button>
                                <div className="flex gap-1">
                                    <Button size="icon-sm" variant="ghost" aria-label="Move up" disabled={index === 0} onClick={() => onMove(index, -1)}>
                                        <ArrowUp aria-hidden />
                                    </Button>
                                    <Button
                                        size="icon-sm"
                                        variant="ghost"
                                        aria-label="Move down"
                                        disabled={index === blocks.length - 1}
                                        onClick={() => onMove(index, 1)}
                                    >
                                        <ArrowDown aria-hidden />
                                    </Button>
                                    <Button
                                        size="icon-sm"
                                        variant="ghost"
                                        aria-label={block.visible ? 'Hide' : 'Show'}
                                        onClick={() => onToggleVisible(block.key)}
                                    >
                                        {block.visible ? <EyeOff aria-hidden /> : <Eye aria-hidden />}
                                    </Button>
                                    <Button size="icon-sm" variant="ghost" aria-label="Remove" onClick={() => onRemove(block.key)}>
                                        <Trash2 aria-hidden />
                                    </Button>
                                </div>
                            </li>
                        );
                    })}
                </ul>
                <div>
                    <Button onClick={onAdd}>
                        <Plus aria-hidden /> Add section
                    </Button>
                </div>
            </div>
        </div>
    );
}
