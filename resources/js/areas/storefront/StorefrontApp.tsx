import { Eye } from 'lucide-react';
import { useEffect } from 'react';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { ApiError } from '@/lib/api';
import { CheckoutProvider } from './checkout/CheckoutProvider';
import { ReturnBanner } from './checkout/ReturnBanner';
import { useStorefront } from './services/storefront';
import { StorefrontView } from './StorefrontView';
import type { StorefrontPayload } from './types';

function Message({ title, children }: { title: string; children?: React.ReactNode }) {
    return (
        <main className="mx-auto flex min-h-screen max-w-lg flex-col items-center justify-center px-6 text-center">
            <h1 className="text-3xl font-semibold tracking-tight">{title}</h1>
            {children && <div className="mt-3 text-muted-foreground">{children}</div>}
        </main>
    );
}

/** Title, description and social tags for the page. Tags are marked so they can be replaced and cleaned up. */
function useSeo(payload: StorefrontPayload | undefined) {
    useEffect(() => {
        if (!payload) return;

        const seo = payload.page?.seo ?? {};
        const title = seo.title || payload.provider.name;
        const tags: [string, string, string | undefined][] = [
            ['name', 'description', seo.description],
            ['property', 'og:title', title],
            ['property', 'og:description', seo.description],
            ['property', 'og:image', seo.og_image_url],
        ];
        document.title = title;

        const added = tags
            .filter(([, , content]) => content)
            .map(([attr, key, content]) => {
                document.head.querySelectorAll(`meta[${attr}="${key}"]`).forEach((m) => m.remove());
                const meta = document.createElement('meta');
                meta.setAttribute(attr, key);
                meta.setAttribute('content', content!);
                meta.setAttribute('data-storefront', '');
                document.head.appendChild(meta);
                return meta;
            });

        return () => added.forEach((m) => m.remove());
    }, [payload]);
}

/** The public site on `<slug>.<base domain>`: loads what the API says is live (or the draft, for a valid preview link). */
export function StorefrontApp() {
    const preview = new URLSearchParams(window.location.search).get('preview');
    const { data, error, isLoading, notFound, refetch } = useStorefront(preview);
    useSeo(data);

    if (isLoading) return <Skeleton className="h-screen w-full rounded-none" />;
    if (notFound) return <Message title="We couldn't find this page">Check the address and try again.</Message>;

    if (error || !data) {
        return (
            <Message title="Something went wrong">
                <Alert>{error instanceof ApiError ? error.message : 'Something went wrong.'}</Alert>
                <Button className="mt-4" variant="outline" onClick={() => refetch()}>
                    Try again
                </Button>
            </Message>
        );
    }

    if (!data.available)
        return <Message title="This storefront is unavailable">{data.provider.name} is not taking sign-ups right now. Please check back later.</Message>;
    if (!data.page) return <Message title="Coming soon">{data.provider.name} is getting ready. Check back shortly.</Message>;

    return (
        <>
            <ReturnBanner search={window.location.search} />
            {data.preview && (
                <div role="status" className="sticky top-0 z-10 flex items-center justify-center gap-2 bg-amber-100 px-4 py-2 text-sm text-amber-950">
                    <Eye aria-hidden className="size-4" /> This is a preview of your draft. Visitors cannot see it.
                </div>
            )}
            {data.preview ? (
                <StorefrontView provider={data.provider} blocks={data.page.blocks} plans={data.plans} />
            ) : (
                <CheckoutProvider providerName={data.provider.name}>
                    <StorefrontView provider={data.provider} blocks={data.page.blocks} plans={data.plans} />
                </CheckoutProvider>
            )}
        </>
    );
}
