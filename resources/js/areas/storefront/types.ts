export type BlockType = 'hero' | 'about' | 'text' | 'image' | 'feature_list' | 'testimonials' | 'faq' | 'cta' | 'plans' | 'contact';

export interface StorefrontBlock {
    id: string;
    type: BlockType;
    // Shape depends on `type` (see resources/js/areas/provider/storefront/blockSchemas.ts and App\Services\Storefront\BlockSchema).
    data: Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any
}

export interface StorefrontPlan {
    id: string;
    name: string;
    description: string | null;
    features: string[];
    trial_days: number;
    prices: { id: string; amount: number; currency: string; interval: string; interval_count: number }[];
}

export interface StorefrontProvider {
    name: string;
    slug: string;
    support_email: string;
    currency: string;
    branding: { primary_color: string | null };
    legal: { terms_url: string | null; privacy_url: string | null; refund_url: string | null };
}

export interface StorefrontSeo {
    title?: string;
    description?: string;
    og_image_url?: string;
}

export interface StorefrontPayload {
    provider: StorefrontProvider;
    available: boolean;
    published: boolean;
    preview: boolean;
    page: { seo: StorefrontSeo; blocks: StorefrontBlock[] } | null;
    plans: StorefrontPlan[];
}
