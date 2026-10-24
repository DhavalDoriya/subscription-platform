import { Link, useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { logout, useAuthStore } from '@/lib/auth';
import { BillingHistory } from './BillingHistory';
import { MySubscriptions } from './MySubscriptions';
import { PaymentIssues } from './PaymentIssues';
import { PaymentMethods } from './PaymentMethods';

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <section aria-labelledby={`section-${title}`} className="mt-10">
            <h2 id={`section-${title}`} className="mb-4 text-xl font-semibold">
                {title}
            </h2>
            {children}
        </section>
    );
}

/** The customer portal: my subscriptions, billing history and saved cards across every provider (FR-CP1 to FR-CP4). */
export function AccountPage() {
    const user = useAuthStore((s) => s.user)!;
    const navigate = useNavigate();

    return (
        <main className="mx-auto max-w-3xl px-4 py-12">
            <h1 className="text-3xl font-semibold">Hello, {user.name}</h1>
            <p className="mt-2 text-muted-foreground">{user.email}</p>
            <nav aria-label="Account" className="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                <Link to="/account/profile" className="text-primary hover:underline">
                    Edit profile
                </Link>
                {user.roles.includes('provider_owner') ? (
                    <Link to="/dashboard" className="text-primary hover:underline">
                        Provider dashboard
                    </Link>
                ) : (
                    <Link to="/become-a-provider" className="text-primary hover:underline">
                        Become a provider
                    </Link>
                )}
            </nav>

            <PaymentIssues />

            <Section title="Subscriptions">
                <MySubscriptions />
            </Section>
            <Section title="Billing history">
                <BillingHistory />
            </Section>
            <Section title="Payment methods">
                <PaymentMethods />
            </Section>

            <Button
                size="lg"
                variant="outline"
                className="mt-12"
                onClick={async () => {
                    await logout();
                    navigate('/login', { replace: true });
                }}
            >
                Sign out
            </Button>
        </main>
    );
}
