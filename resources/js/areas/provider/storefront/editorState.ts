import type { BlockType, StorefrontBlock, StorefrontSeo } from '@/areas/storefront/types';
import type { Data } from './blockSchemas';
import type { DraftBlock, DraftInput, StorefrontDraft } from '../services/storefront';

/** A block as the editor holds it: `key` is stable for React, `id` exists only once the server has stored it. */
export interface EditorBlock {
    key: string;
    id?: string;
    type: BlockType;
    visible: boolean;
    data: Data;
}

let counter = 0;
export const newKey = () => `new-${++counter}`;

export const fromServer = (draft: StorefrontDraft): EditorBlock[] =>
    draft.blocks.map((b) => ({ key: b.id, id: b.id, type: b.type, visible: b.visible, data: b.data }));

export const toInput = (blocks: EditorBlock[], seo: StorefrontSeo): DraftInput => ({
    seo,
    blocks: blocks.map<DraftBlock>((b) => ({ ...(b.id ? { id: b.id } : {}), type: b.type, visible: b.visible, data: b.data })),
});

/** The blocks visitors would see, in the shape the storefront renderer takes. */
export const visibleBlocks = (blocks: EditorBlock[]): StorefrontBlock[] =>
    blocks.filter((b) => b.visible).map((b) => ({ id: b.key, type: b.type, data: b.data }));

/** After a save the server assigns ids to new blocks; the response lists blocks in the order we sent them. */
export const adoptIds = (blocks: EditorBlock[], saved: StorefrontDraft): EditorBlock[] => blocks.map((b, i) => ({ ...b, id: saved.blocks[i]?.id ?? b.id }));
