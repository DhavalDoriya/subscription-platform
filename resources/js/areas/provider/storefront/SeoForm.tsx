import { ImageField } from '@/components/media/ImageField';
import { TextAreaField } from '@/components/ui/TextAreaField';
import { TextField } from '@/components/ui/TextField';
import type { StorefrontSeo } from '@/areas/storefront/types';

interface Props {
    seo: StorefrontSeo;
    errors: (field: string) => string | undefined;
    onChange: (seo: StorefrontSeo) => void;
}

/** Page title, description and share image (FR-S4). */
export function SeoForm({ seo, errors, onChange }: Props) {
    return (
        <div className="grid gap-4">
            <TextField
                label="Page title"
                value={seo.title ?? ''}
                error={errors('seo.title')}
                hint="Up to 70 characters."
                onChange={(e) => onChange({ ...seo, title: e.target.value })}
            />
            <TextAreaField
                label="Meta description"
                rows={2}
                value={seo.description ?? ''}
                error={errors('seo.description')}
                hint="Up to 160 characters."
                onChange={(e) => onChange({ ...seo, description: e.target.value })}
            />
            <ImageField
                label="Share image"
                hint="Shown when your page is shared. Upload an image or paste an https:// link."
                value={seo.og_image_url ?? ''}
                error={errors('seo.og_image_url')}
                onChange={(url) => onChange({ ...seo, og_image_url: url })}
            />
        </div>
    );
}
