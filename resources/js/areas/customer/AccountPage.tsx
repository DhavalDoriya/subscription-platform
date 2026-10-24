import { Link, useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { logout, useAuthStore } from '@/lib/auth';

/** Placeholder landing page for signed-in users until the customer portal arrives (Phase 5). */
export function AccountPage() {
    const user = useAuthStore((s) => s.user)!;
    const navigate = useNavigate();

    return (
        <main className="mx-auto max-w-2xl px-4 py-16">
            <h1 className="text-3xl font-semibold">Hello, {user.name}</h1>
            <p className="mt-2 text-muted-foreground">{user.email}</p>
            <Link to="/account/profile" className="mt-6 inline-block text-sm text-primary hover:underline">
                Edit profile
            </Link>
            <br />
            {user.roles.includes('provider_owner') ? (
                <Link to="/dashboard" className="mt-3 inline-block text-sm text-primary hover:underline">
                    Provider dashboard
                </Link>
            ) : (
                <Link to="/become-a-provider" className="mt-3 inline-block text-sm text-primary hover:underline">
                    Become a provider
                </Link>
            )}
            <br />
            <Button
                size="lg"
                className="mt-6"
                variant="outline"
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
