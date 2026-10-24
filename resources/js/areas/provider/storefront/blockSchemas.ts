import type { BlockType } from '@/areas/storefront/types';

export type Data = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

export interface FieldDef {
    key: string;
    label: string;
    kind: 'text' | 'textarea' | 'html' | 'url' | 'email' | 'image';
    hint?: string;
}

export interface ListDef {
    key: 'items';
    fields: FieldDef[];
    max: number;
    empty: Data;
}

export interface BlockDef {
    type: BlockType;
    label: string;
    /** One line shown in the add-section picker. */
    description: string;
    fields: FieldDef[];
    list?: ListDef;
    defaults: () => Data;
    /** One line shown on the collapsed card. */
    summary: (data: Data) => string;
}

const HTML_HINT = 'Basic formatting only: <p>, <strong>, <em>, <ul>, <li>, <a href>, headings.';
const heading: FieldDef = { key: 'heading', label: 'Heading', kind: 'text' };

/** What the editor offers per block type. Keep in step with App\Services\Storefront\BlockSchema, which is the authority. */
export const blockDefs: Record<BlockType, BlockDef> = {
    hero: {
        type: 'hero',
        label: 'Hero',
        description: 'Headline, button and image at the top of the page',
        fields: [
            { key: 'headline', label: 'Headline', kind: 'text' },
            { key: 'subheadline', label: 'Subheadline', kind: 'text' },
            { key: 'button_label', label: 'Button label', kind: 'text' },
            { key: 'button_url', label: 'Button link', kind: 'url' },
            { key: 'image_url', label: 'Image', kind: 'image', hint: 'Upload a JPG, PNG, WebP or GIF, or paste an https:// link.' },
        ],
        defaults: () => ({ headline: 'Your headline', subheadline: '', button_label: '', button_url: '', image_url: '' }),
        summary: (d) => d.headline ?? '',
    },
    about: {
        type: 'about',
        label: 'About',
        description: 'Tell visitors who you are',
        fields: [heading, { key: 'body', label: 'Text', kind: 'html', hint: HTML_HINT }],
        defaults: () => ({ heading: 'About us', body: '<p>Tell visitors about your business.</p>' }),
        summary: (d) => d.heading ?? '',
    },
    text: {
        type: 'text',
        label: 'Text',
        description: 'A free-form paragraph of text',
        fields: [{ key: 'body', label: 'Text', kind: 'html', hint: HTML_HINT }],
        defaults: () => ({ body: '<p>Write something.</p>' }),
        summary: (d) =>
            String(d.body ?? '')
                .replace(/<[^>]+>/g, ' ')
                .trim()
                .slice(0, 60),
    },
    image: {
        type: 'image',
        label: 'Image',
        description: 'A single picture with a caption',
        fields: [
            { key: 'image_url', label: 'Image', kind: 'image', hint: 'Upload a JPG, PNG, WebP or GIF, or paste an https:// link.' },
            { key: 'alt', label: 'Alt text', kind: 'text', hint: 'Describe the image for people who cannot see it.' },
            { key: 'caption', label: 'Caption', kind: 'text' },
        ],
        defaults: () => ({ image_url: '', alt: '', caption: '' }),
        summary: (d) => d.alt ?? '',
    },
    feature_list: {
        type: 'feature_list',
        label: 'Feature list',
        description: 'Highlight what makes you different',
        fields: [heading],
        list: {
            key: 'items',
            max: 12,
            empty: { title: '', description: '' },
            fields: [
                { key: 'title', label: 'Title', kind: 'text' },
                { key: 'description', label: 'Description', kind: 'text' },
            ],
        },
        defaults: () => ({ heading: 'Why choose us', items: [{ title: 'Feature one', description: '' }] }),
        summary: (d) => d.heading ?? '',
    },
    testimonials: {
        type: 'testimonials',
        label: 'Testimonials',
        description: 'Quotes from happy customers',
        fields: [heading],
        list: {
            key: 'items',
            max: 12,
            empty: { quote: '', author: '', role: '' },
            fields: [
                { key: 'quote', label: 'Quote', kind: 'textarea' },
                { key: 'author', label: 'Author', kind: 'text' },
                { key: 'role', label: 'Role', kind: 'text' },
            ],
        },
        defaults: () => ({ heading: 'What customers say', items: [{ quote: 'Great service!', author: 'A happy customer', role: '' }] }),
        summary: (d) => d.heading ?? '',
    },
    faq: {
        type: 'faq',
        label: 'FAQ',
        description: 'Answer common questions',
        fields: [heading],
        list: {
            key: 'items',
            max: 20,
            empty: { question: '', answer: '' },
            fields: [
                { key: 'question', label: 'Question', kind: 'text' },
                { key: 'answer', label: 'Answer', kind: 'html', hint: HTML_HINT },
            ],
        },
        defaults: () => ({ heading: 'Questions', items: [{ question: 'A common question?', answer: '<p>The answer.</p>' }] }),
        summary: (d) => d.heading ?? '',
    },
    cta: {
        type: 'cta',
        label: 'Call to action',
        description: 'A prominent call to action',
        fields: [
            { key: 'heading', label: 'Heading', kind: 'text' },
            { key: 'text', label: 'Text', kind: 'text' },
            { key: 'button_label', label: 'Button label', kind: 'text' },
            { key: 'button_url', label: 'Button link', kind: 'url' },
        ],
        defaults: () => ({ heading: 'Ready to start?', text: '', button_label: 'Get started', button_url: '' }),
        summary: (d) => d.heading ?? '',
    },
    plans: {
        type: 'plans',
        label: 'Plans',
        description: 'Your live plans and prices',
        fields: [heading, { key: 'subheading', label: 'Subheading', kind: 'text' }],
        defaults: () => ({ heading: 'Pricing', subheading: '' }),
        summary: (d) => d.heading ?? 'Your live plans',
    },
    contact: {
        type: 'contact',
        label: 'Contact',
        description: 'How visitors can reach you',
        fields: [
            heading,
            { key: 'body', label: 'Message', kind: 'text' },
            { key: 'email', label: 'Email', kind: 'email', hint: 'Leave empty to use your support email.' },
        ],
        defaults: () => ({ heading: 'Get in touch', body: '', email: '' }),
        summary: (d) => d.email || d.heading || '',
    },
};

export const blockTypes = Object.keys(blockDefs) as BlockType[];
