import type { CSSProperties } from 'react';
import { BlockRenderer } from './blocks';
import { readableOn } from './color';
import type { StorefrontBlock, StorefrontPlan, StorefrontProvider } from './types';

/** The storefront page itself: header, blocks, footer, in the provider's brand colour. Shared by the site and the editor preview. */
export function StorefrontView({
    provider,
    blocks,
    plans,
    embedded = false,
}: {
    provider: StorefrontProvider;
    blocks: StorefrontBlock[];
    plans: StorefrontPlan[];
    embedded?: boolean;
}) {
    const brand = provider.branding.primary_color;
    const style = brand ? ({ '--primary': brand, '--primary-foreground': readableOn(brand), '--ring': brand } as CSSProperties) : undefined;
    const { legal } = provider;
    const links = [
        ['Terms of service', legal.terms_url],
        ['Privacy policy', legal.privacy_url],
        ['Refund policy', legal.refund_url],
    ] as const;

    return (
        <div data-storefront-root style={style} className={`flex flex-col bg-background text-foreground ${embedded ? '' : 'min-h-screen'}`}>
            <nav aria-label="Storefront" className="border-b bg-card">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                    <span className="text-lg font-semibold">{provider.name}</span>
                    <a href="#plans" className="text-sm text-muted-foreground hover:text-foreground">
                        Plans
                    </a>
                </div>
            </nav>

            <main className="flex-1">
                {blocks.map((block) => (
                    <BlockRenderer key={block.id} block={block} plans={plans} supportEmail={provider.support_email} />
                ))}
            </main>

            <footer className="border-t bg-card">
                <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-6 py-8 text-sm text-muted-foreground">
                    <p>
                        © {new Date().getFullYear()} {provider.name}
                    </p>
                    <ul className="flex flex-wrap gap-5">
                        {links.map(([label, href]) =>
                            href ? (
                                <li key={label}>
                                    <a href={href} rel="noopener noreferrer" className="hover:text-foreground">
                                        {label}
                                    </a>
                                </li>
                            ) : null,
                        )}
                        <li>
                            <a href={`mailto:${provider.support_email}`} className="hover:text-foreground">
                                {provider.support_email}
                            </a>
                        </li>
                    </ul>
                </div>
            </footer>
        </div>
    );
}
