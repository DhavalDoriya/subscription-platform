import { Button } from '@/components/ui/button';
import { formatPrice } from '@/lib/money';
import { useCheckout } from './checkout/CheckoutProvider';
import { SafeHtml } from './SafeHtml';
import type { StorefrontBlock, StorefrontPlan } from './types';

type Data = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

const section = 'mx-auto w-full max-w-5xl px-6 py-14';
const external = 'noopener noreferrer';

function LinkButton({ label, url, variant = 'default' }: { label?: string | null; url?: string | null; variant?: 'default' | 'outline' }) {
    if (!label || !url) return null;

    return (
        <Button size="lg" variant={variant} render={<a href={url} rel={external} />}>
            {label}
        </Button>
    );
}

function Hero({ data }: { data: Data }) {
    return (
        <header className="relative overflow-hidden border-b bg-[color-mix(in_oklab,var(--primary)_7%,var(--background))]">
            <div className={`${section} grid items-center gap-10 py-20 ${data.image_url ? 'md:grid-cols-2' : ''}`}>
                <div>
                    <h1 className="text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">{data.headline}</h1>
                    {data.subheadline && <p className="mt-5 max-w-xl text-lg text-muted-foreground">{data.subheadline}</p>}
                    <div className="mt-8">
                        <LinkButton label={data.button_label} url={data.button_url} />
                    </div>
                </div>
                {data.image_url && <img src={data.image_url} alt="" className="aspect-[4/3] w-full rounded-2xl object-cover shadow-sm" />}
            </div>
        </header>
    );
}

function RichSection({ data }: { data: Data }) {
    return (
        <section className={`${section} max-w-3xl`}>
            {data.heading && <h2 className="mb-4 text-3xl font-semibold tracking-tight">{data.heading}</h2>}
            <SafeHtml
                html={data.body ?? ''}
                className="space-y-4 text-lg leading-relaxed [&_a]:text-primary [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:pl-4 [&_h2]:text-2xl [&_h2]:font-semibold [&_h3]:text-xl [&_h3]:font-semibold [&_ol]:list-decimal [&_ol]:pl-6 [&_ul]:list-disc [&_ul]:pl-6"
            />
        </section>
    );
}

function ImageBlock({ data }: { data: Data }) {
    return (
        <figure className={`${section} max-w-4xl`}>
            <img src={data.image_url} alt={data.alt} className="w-full rounded-2xl object-cover" />
            {data.caption && <figcaption className="mt-3 text-center text-sm text-muted-foreground">{data.caption}</figcaption>}
        </figure>
    );
}

function FeatureList({ data }: { data: Data }) {
    return (
        <section className={section}>
            {data.heading && <h2 className="mb-8 text-3xl font-semibold tracking-tight">{data.heading}</h2>}
            <ul className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                {(data.items ?? []).map((item: Data, i: number) => (
                    <li key={i} className="rounded-xl border bg-card p-6">
                        <h3 className="font-semibold">{item.title}</h3>
                        {item.description && <p className="mt-2 text-muted-foreground">{item.description}</p>}
                    </li>
                ))}
            </ul>
        </section>
    );
}

function Testimonials({ data }: { data: Data }) {
    return (
        <section className={section}>
            {data.heading && <h2 className="mb-8 text-3xl font-semibold tracking-tight">{data.heading}</h2>}
            <div className="grid gap-6 md:grid-cols-2">
                {(data.items ?? []).map((item: Data, i: number) => (
                    <figure key={i} className="rounded-xl bg-muted/60 p-6">
                        <blockquote className="text-lg leading-relaxed">{item.quote}</blockquote>
                        <figcaption className="mt-4 text-sm text-muted-foreground">
                            <span className="font-medium text-foreground">{item.author}</span>
                            {item.role && <span>, {item.role}</span>}
                        </figcaption>
                    </figure>
                ))}
            </div>
        </section>
    );
}

function Faq({ data }: { data: Data }) {
    return (
        <section className={`${section} max-w-3xl`}>
            {data.heading && <h2 className="mb-6 text-3xl font-semibold tracking-tight">{data.heading}</h2>}
            <div className="divide-y rounded-xl border bg-card">
                {(data.items ?? []).map((item: Data, i: number) => (
                    <details key={i} className="group px-5 py-4">
                        <summary className="cursor-pointer list-none font-medium marker:content-none">{item.question}</summary>
                        <SafeHtml html={item.answer ?? ''} className="mt-3 space-y-3 text-muted-foreground" />
                    </details>
                ))}
            </div>
        </section>
    );
}

function Cta({ data }: { data: Data }) {
    return (
        <section className={section}>
            <div className="rounded-3xl bg-primary px-8 py-14 text-center text-primary-foreground">
                <h2 className="text-3xl font-semibold tracking-tight">{data.heading}</h2>
                {data.text && <p className="mx-auto mt-3 max-w-xl opacity-90">{data.text}</p>}
                <div className="mt-8">
                    <Button size="lg" variant="secondary" render={<a href={data.button_url} rel={external} />}>
                        {data.button_label}
                    </Button>
                </div>
            </div>
        </section>
    );
}

/** One button per price. Where checkout is unavailable (the editor preview) they are shown but disabled. */
function SubscribeButtons({ plan }: { plan: StorefrontPlan }) {
    const checkout = useCheckout();
    const several = plan.prices.length > 1;

    return (
        <div className="mt-6 grid gap-2">
            {plan.prices.map((price) => (
                <Button
                    key={price.id}
                    size="lg"
                    disabled={!checkout || plan.prices.length === 0}
                    title={checkout ? undefined : 'Checkout is available on your live storefront'}
                    onClick={() => checkout?.start(plan, price)}
                >
                    {several ? `Subscribe · ${formatPrice(price)}` : 'Subscribe'}
                </Button>
            ))}
        </div>
    );
}

function Plans({ data, plans }: { data: Data; plans: StorefrontPlan[] }) {
    return (
        <section className={section} id="plans">
            <h2 className="text-3xl font-semibold tracking-tight">{data.heading ?? 'Plans'}</h2>
            {data.subheading && <p className="mt-2 text-muted-foreground">{data.subheading}</p>}
            {plans.length === 0 ? (
                <p className="mt-8 rounded-xl border border-dashed p-8 text-center text-muted-foreground">Plans are coming soon.</p>
            ) : (
                <div className="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    {plans.map((plan) => (
                        <article key={plan.id} aria-labelledby={`plan-${plan.id}`} className="flex flex-col rounded-2xl border bg-card p-7 shadow-sm">
                            <h3 id={`plan-${plan.id}`} className="text-xl font-semibold">
                                {plan.name}
                            </h3>
                            {plan.description && <p className="mt-1 text-sm text-muted-foreground">{plan.description}</p>}
                            <div className="mt-5 space-y-1">
                                {plan.prices.map((price) => (
                                    <p key={price.id} className="text-2xl font-semibold">
                                        {formatPrice(price)}
                                    </p>
                                ))}
                            </div>
                            {plan.trial_days > 0 && <p className="mt-2 text-sm font-medium text-primary">{plan.trial_days}-day free trial</p>}
                            <ul className="mt-5 flex-1 space-y-2 text-sm">
                                {plan.features.map((feature) => (
                                    <li key={feature} className="flex gap-2">
                                        <span aria-hidden className="text-primary">
                                            ✓
                                        </span>
                                        {feature}
                                    </li>
                                ))}
                            </ul>
                            <SubscribeButtons plan={plan} />
                        </article>
                    ))}
                </div>
            )}
        </section>
    );
}

function Contact({ data, fallbackEmail }: { data: Data; fallbackEmail: string }) {
    const email = data.email || fallbackEmail;

    return (
        <section className={`${section} max-w-3xl text-center`}>
            <h2 className="text-3xl font-semibold tracking-tight">{data.heading ?? 'Contact'}</h2>
            {data.body && <p className="mt-3 text-muted-foreground">{data.body}</p>}
            <p className="mt-4">
                <a className="text-lg text-primary underline" href={`mailto:${email}`}>
                    {email}
                </a>
            </p>
        </section>
    );
}

/** One renderer per block type. Used by the live storefront and by the editor's preview, so they cannot drift apart. */
export function BlockRenderer({ block, plans, supportEmail }: { block: StorefrontBlock; plans: StorefrontPlan[]; supportEmail: string }) {
    switch (block.type) {
        case 'hero':
            return <Hero data={block.data} />;
        case 'about':
        case 'text':
            return <RichSection data={block.data} />;
        case 'image':
            return <ImageBlock data={block.data} />;
        case 'feature_list':
            return <FeatureList data={block.data} />;
        case 'testimonials':
            return <Testimonials data={block.data} />;
        case 'faq':
            return <Faq data={block.data} />;
        case 'cta':
            return <Cta data={block.data} />;
        case 'plans':
            return <Plans data={block.data} plans={plans} />;
        case 'contact':
            return <Contact data={block.data} fallbackEmail={supportEmail} />;
        default:
            return null;
    }
}
