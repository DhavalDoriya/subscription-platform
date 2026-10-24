import { Plus, X } from 'lucide-react';
import { ImageField } from '@/components/media/ImageField';
import { Button } from '@/components/ui/button';
import { TextAreaField } from '@/components/ui/TextAreaField';
import { TextField } from '@/components/ui/TextField';
import { blockDefs, type Data, type FieldDef } from './blockSchemas';
import type { EditorBlock } from './editorState';

interface Props {
    block: EditorBlock;
    /** Server validation messages for this block, keyed by field path relative to `data` (e.g. "items.0.title"). */
    errors: (path: string) => string | undefined;
    onChange: (data: Data) => void;
}

function Field({ def, id, value, error, onChange }: { def: FieldDef; id: string; value: string; error?: string; onChange: (v: string) => void }) {
    if (def.kind === 'textarea' || def.kind === 'html') {
        return (
            <TextAreaField
                id={id}
                label={def.label}
                hint={def.hint}
                error={error}
                value={value}
                rows={def.kind === 'html' ? 5 : 3}
                onChange={(e) => onChange(e.target.value)}
            />
        );
    }

    if (def.kind === 'image') {
        return <ImageField label={def.label} hint={def.hint} error={error} value={value} onChange={onChange} />;
    }

    return (
        <TextField
            id={id}
            label={def.label}
            hint={def.hint}
            error={error}
            type={def.kind === 'email' ? 'email' : def.kind === 'url' ? 'url' : 'text'}
            value={value}
            onChange={(e) => onChange(e.target.value)}
        />
    );
}

/** The fields of one block (and its repeatable items). The page shows exactly one of these at a time. */
export function BlockForm({ block, errors, onChange }: Props) {
    const def = blockDefs[block.type];
    const items: Data[] = block.data.items ?? [];

    return (
        <div className="grid gap-4">
            {def.fields.map((field) => (
                <Field
                    key={field.key}
                    def={field}
                    id={`${block.key}-${field.key}`}
                    value={block.data[field.key] ?? ''}
                    error={errors(field.key)}
                    onChange={(v) => onChange({ ...block.data, [field.key]: v })}
                />
            ))}

            {def.list && (
                <div className="grid gap-3">
                    {items.map((item, i) => (
                        <fieldset key={i} className="grid gap-3 rounded-md border p-3">
                            <legend className="px-1 text-xs text-muted-foreground">Item {i + 1}</legend>
                            {def.list!.fields.map((field) => (
                                <Field
                                    key={field.key}
                                    def={field}
                                    id={`${block.key}-items-${i}-${field.key}`}
                                    value={item[field.key] ?? ''}
                                    error={errors(`items.${i}.${field.key}`)}
                                    onChange={(v) => onChange({ ...block.data, items: items.map((it, j) => (j === i ? { ...it, [field.key]: v } : it)) })}
                                />
                            ))}
                            <div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    aria-label="Remove item"
                                    onClick={() => onChange({ ...block.data, items: items.filter((_, j) => j !== i) })}
                                >
                                    <X aria-hidden /> Remove item
                                </Button>
                            </div>
                        </fieldset>
                    ))}
                    {errors('items') && <p className="text-sm text-destructive">{errors('items')}</p>}
                    {items.length < def.list.max && (
                        <div>
                            <Button size="sm" variant="outline" onClick={() => onChange({ ...block.data, items: [...items, { ...def.list!.empty }] })}>
                                <Plus aria-hidden /> Add item
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
