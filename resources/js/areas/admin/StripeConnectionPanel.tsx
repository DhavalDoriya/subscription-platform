import { StatusBadge } from '@/components/StatusBadge';
import { useStripeInfo } from './services/stripe';

/** What an admin needs to wire Stripe up: the mode the key is in, which URLs to register, and which secrets are saved. */
export function StripeConnectionPanel() {
    const { data } = useStripeInfo();

    if (!data) return null;

    return (
        <section aria-label="Stripe connection" className="mb-6 rounded-lg border bg-card p-6">
            <div className="flex items-center justify-between">
                <h2 className="text-lg font-semibold">Stripe connection</h2>
                {data.configured ? (
                    <StatusBadge label={data.mode === 'live' ? 'Live mode' : 'Test mode'} tone={data.mode === 'live' ? 'good' : 'warn'} />
                ) : (
                    <StatusBadge label="Not configured" tone="bad" />
                )}
            </div>
            <p className="mt-1 text-sm text-muted-foreground">
                Register these two URLs as webhook endpoints in your Stripe dashboard, then save their signing secrets below.
            </p>
            <dl className="mt-4 grid gap-3 text-sm">
                <div>
                    <dt className="font-medium">Platform events</dt>
                    <dd>
                        <code className="break-all text-xs">{data.webhook_url}</code>
                    </dd>
                    <dd className="text-muted-foreground">{`Webhook secret: ${data.webhook_secret_set ? 'set' : 'not set'}`}</dd>
                </div>
                <div>
                    <dt className="font-medium">Connected account events</dt>
                    <dd>
                        <code className="break-all text-xs">{data.connect_webhook_url}</code>
                    </dd>
                    <dd className="text-muted-foreground">{`Connect webhook secret: ${data.connect_webhook_secret_set ? 'set' : 'not set'}`}</dd>
                </div>
            </dl>
        </section>
    );
}
