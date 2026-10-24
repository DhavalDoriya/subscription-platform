import { ArrowLeft, ExternalLink, Monitor, Smartphone } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { PageHeader } from '@/components/PageHeader';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { StorefrontView } from '@/areas/storefront/StorefrontView';
import type { StorefrontPlan, StorefrontSeo } from '@/areas/storefront/types';
import { ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { openInNewTab } from '@/lib/navigate';
import { AddSectionDialog } from './storefront/AddSectionDialog';
import { blockDefs } from './storefront/blockSchemas';
import { BlockForm } from './storefront/BlockForm';
import { SectionList } from './storefront/SectionList';
import { SeoForm } from './storefront/SeoForm';
import { adoptIds, fromServer, newKey, toInput, visibleBlocks, type EditorBlock } from './storefront/editorState';
import { usePlans } from './services/plans';
import { useMyProvider, type MyProvider } from './services/provider';
import { useStorefrontActions, useStorefrontDraft, type StorefrontDraft } from './services/storefront';
import type { BlockType } from '@/areas/storefront/types';

function Editor({ draft, provider }: { draft: StorefrontDraft; provider: MyProvider }) {
    const actions = useStorefrontActions();
    const { data: planList } = usePlans({ status: 'active', per_page: 50 });
    const [blocks, setBlocks] = useState<EditorBlock[]>(() => fromServer(draft));
    const [seo, setSeo] = useState<StorefrontSeo>(draft.seo ?? {});
    const [baseline, setBaseline] = useState(() => JSON.stringify(toInput(fromServer(draft), draft.seo ?? {})));
    const [selected, setSelected] = useState<'seo' | string | null>(null); // the section whose form is open
    const [adding, setAdding] = useState(false);
    const [view, setView] = useState<'editor' | 'preview'>('editor');
    const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');
    const [error, setError] = useState<ApiError | null>(null);
    const [savedNote, setSavedNote] = useState(false);
    const [published, setPublished] = useState({ at: draft.published_at, stale: draft.has_unpublished_changes });

    const input = useMemo(() => toInput(blocks, seo), [blocks, seo]);
    const dirty = JSON.stringify(input) !== baseline;
    const busy = actions.save.isPending || actions.publish.isPending || actions.unpublish.isPending;

    // Warn before a reload or tab close would throw edits away.
    useEffect(() => {
        if (!dirty) return;
        const warn = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    const plans: StorefrontPlan[] = useMemo(
        () =>
            (planList?.data ?? [])
                .filter((p) => p.visibility === 'public')
                .map((p) => ({
                    id: p.id,
                    name: p.name,
                    description: p.description,
                    features: p.features,
                    trial_days: p.trial_days,
                    prices: p.prices.filter((x) => x.status === 'active'),
                })),
        [planList],
    );

    const update = (key: string, patch: Partial<EditorBlock>) => {
        setSavedNote(false);
        setBlocks((all) => all.map((b) => (b.key === key ? { ...b, ...patch } : b)));
    };
    const fieldError = (index: number) => (path: string) => error?.firstError(`blocks.${index}.data.${path}`);
    const errorKeys = Object.keys(error?.fieldErrors ?? {});
    const blockHasError = (index: number) => errorKeys.some((k) => k.startsWith(`blocks.${index}.`));
    const seoHasError = errorKeys.some((k) => k.startsWith('seo.'));

    /** After a rejected save, open the first section with a problem so its message is in front of the owner. */
    function revealFirstError(e: ApiError) {
        const keys = Object.keys(e.fieldErrors);
        const indexes = keys
            .map((k) => /^blocks\.(\d+)\./.exec(k)?.[1])
            .filter((i): i is string => i !== undefined)
            .map(Number);

        if (keys.some((k) => k.startsWith('seo.'))) setSelected('seo');
        else if (indexes.length) setSelected(blocks[Math.min(...indexes)]?.key ?? null);
        else return;
        setView('editor');
    }

    function addBlock(type: BlockType) {
        const block: EditorBlock = { key: newKey(), type, visible: true, data: blockDefs[type].defaults() };
        setSavedNote(false);
        setBlocks((all) => [...all, block]);
        setSelected(block.key);
        setView('editor');
        setAdding(false);
    }

    function moveBlock(index: number, delta: -1 | 1) {
        setSavedNote(false);
        setError(null); // field errors are keyed by position
        setBlocks((all) => {
            const next = [...all];
            [next[index], next[index + delta]] = [next[index + delta], next[index]];
            return next;
        });
    }

    function removeBlock(key: string) {
        setSavedNote(false);
        setError(null);
        if (selected === key) setSelected(null);
        setBlocks((all) => all.filter((b) => b.key !== key));
    }

    const selectedIndex = blocks.findIndex((b) => b.key === selected);
    const selectedBlock = selectedIndex >= 0 ? blocks[selectedIndex] : null;

    async function save(): Promise<boolean> {
        setError(null);
        try {
            const saved = await actions.save.mutateAsync(input);
            const adopted = adoptIds(blocks, saved);
            const savedSeo = saved.seo ?? seo;
            setBlocks(adopted);
            setSeo(savedSeo); // adopt the server's copy so the dirty check does not depend on key order or trimming
            setBaseline(JSON.stringify(toInput(adopted, savedSeo)));
            setPublished({ at: saved.published_at, stale: saved.has_unpublished_changes });
            setSavedNote(true);
            return true;
        } catch (e) {
            const failure = e instanceof ApiError ? e : new ApiError('Something went wrong.', 0);
            setError(failure);
            revealFirstError(failure);
            return false;
        }
    }

    async function run(action: () => Promise<StorefrontDraft>, success: string) {
        setError(null);
        try {
            const result = await action();
            setPublished({ at: result.published_at, stale: result.has_unpublished_changes });
            toast.success(success);
        } catch (e) {
            setError(e instanceof ApiError ? e : new ApiError('Something went wrong.', 0));
        }
    }

    const publish = async () => {
        if (dirty && !(await save())) return;
        await run(() => actions.publish.mutateAsync(), 'Storefront published');
    };

    async function preview() {
        try {
            const { url } = await actions.preview.mutateAsync();
            openInNewTab(url);
        } catch (e) {
            setError(e instanceof ApiError ? e : new ApiError('Something went wrong.', 0));
        }
    }

    const status = dirty ? 'Unsaved changes' : savedNote ? 'Draft saved' : 'No unsaved changes';

    return (
        <>
            <PageHeader
                title="Storefront"
                description={`Your public page at ${provider.slug}. Edit the draft, check the preview, then publish.`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <span role="status" className="mr-2 text-sm text-muted-foreground">
                            {status}
                        </span>
                        <div role="group" aria-label="View" className="flex rounded-lg border p-0.5">
                            {(['editor', 'preview'] as const).map((v) => (
                                <Button key={v} size="sm" variant={view === v ? 'default' : 'ghost'} aria-pressed={view === v} onClick={() => setView(v)}>
                                    {v === 'editor' ? 'Editor' : 'Preview'}
                                </Button>
                            ))}
                        </div>
                        <Button variant="outline" onClick={preview} loading={actions.preview.isPending}>
                            <ExternalLink aria-hidden /> Preview in new tab
                        </Button>
                        <Button variant="outline" disabled={!dirty || busy} loading={actions.save.isPending} onClick={() => void save()}>
                            Save draft
                        </Button>
                        <Button disabled={busy} onClick={publish}>
                            Publish
                        </Button>
                        {published.at && (
                            <Button variant="outline" disabled={busy} onClick={() => run(() => actions.unpublish.mutateAsync(), 'Storefront unpublished')}>
                                Unpublish
                            </Button>
                        )}
                    </div>
                }
            />

            {published.at && (
                <p className="mb-4 text-sm text-muted-foreground">
                    Published {formatDate(published.at)}.{' '}
                    {(published.stale || dirty) && <strong className="text-foreground">Some changes are not published yet.</strong>}
                </p>
            )}
            {error && !error.isValidation && (
                <div className="mb-4">
                    <Alert>{error.message}</Alert>
                </div>
            )}
            {error?.isValidation && (
                <div className="mb-4">
                    <Alert>Some fields need attention. They are marked below.</Alert>
                </div>
            )}

            <div className={view === 'editor' ? 'grid gap-8 xl:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]' : 'grid'}>
                {view === 'editor' && (
                    <div className="content-start">
                        {selected === 'seo' || selectedBlock ? (
                            <section aria-label="Section editor" className="grid gap-4 rounded-lg border bg-card p-4">
                                <div className="flex items-center gap-2">
                                    <Button size="sm" variant="outline" onClick={() => setSelected(null)}>
                                        <ArrowLeft aria-hidden /> Back to sections
                                    </Button>
                                </div>
                                <h2 className="text-base font-medium">{selectedBlock ? blockDefs[selectedBlock.type].label : 'Search and sharing'}</h2>
                                {selectedBlock ? (
                                    <BlockForm
                                        block={selectedBlock}
                                        errors={fieldError(selectedIndex)}
                                        onChange={(data) => update(selectedBlock.key, { data })}
                                    />
                                ) : (
                                    <SeoForm
                                        seo={seo}
                                        errors={(field) => error?.firstError(field)}
                                        onChange={(next) => {
                                            setSavedNote(false);
                                            setSeo(next);
                                        }}
                                    />
                                )}
                            </section>
                        ) : (
                            <SectionList
                                blocks={blocks}
                                hasError={blockHasError}
                                seoHasError={seoHasError}
                                onSelect={setSelected}
                                onMove={moveBlock}
                                onToggleVisible={(key) => update(key, { visible: !blocks.find((b) => b.key === key)?.visible })}
                                onRemove={removeBlock}
                                onAdd={() => setAdding(true)}
                            />
                        )}
                    </div>
                )}

                <section aria-label="Live preview" className="min-w-0 overflow-hidden rounded-lg border">
                    <div className="flex items-center justify-between gap-2 border-b bg-muted/50 px-4 py-2 text-xs text-muted-foreground">
                        <span>Live preview of your draft</span>
                        <div role="group" aria-label="Preview size" className="flex gap-1">
                            <Button
                                size="xs"
                                variant={device === 'desktop' ? 'default' : 'ghost'}
                                aria-pressed={device === 'desktop'}
                                onClick={() => setDevice('desktop')}
                            >
                                <Monitor aria-hidden /> Desktop
                            </Button>
                            <Button
                                size="xs"
                                variant={device === 'mobile' ? 'default' : 'ghost'}
                                aria-pressed={device === 'mobile'}
                                onClick={() => setDevice('mobile')}
                            >
                                <Smartphone aria-hidden /> Mobile
                            </Button>
                        </div>
                    </div>
                    <div className="max-h-[80vh] overflow-y-auto bg-muted/30">
                        <div
                            data-testid="preview-frame"
                            data-device={device}
                            className={device === 'mobile' ? 'mx-auto max-w-[390px] border-x bg-background' : 'bg-background'}
                        >
                            <StorefrontView
                                embedded
                                provider={{
                                    name: provider.name,
                                    slug: provider.slug,
                                    support_email: provider.support_email,
                                    currency: provider.currency,
                                    branding: provider.branding,
                                    legal: provider.legal,
                                }}
                                blocks={visibleBlocks(blocks)}
                                plans={plans}
                            />
                        </div>
                    </div>
                </section>
            </div>

            <AddSectionDialog open={adding} onOpenChange={setAdding} onPick={addBlock} />
        </>
    );
}

export function StorefrontEditorPage() {
    const { data: draft, error } = useStorefrontDraft();
    const { data: provider } = useMyProvider();

    if (error) return <Alert>{error instanceof ApiError ? error.message : 'Something went wrong.'}</Alert>;
    if (!draft || !provider) return <Skeleton className="h-96 w-full" />;

    return <Editor key={provider.id} draft={draft} provider={provider} />;
}
