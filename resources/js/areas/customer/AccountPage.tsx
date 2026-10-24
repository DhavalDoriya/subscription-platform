import { useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/Button';
import { logout, useAuthStore } from '@/lib/auth';

/** Placeholder landing page for signed-in users until the customer portal arrives (Phase 5). */
export function AccountPage() {
    const user = useAuthStore((s) => s.user)!;
    const navigate = useNavigate();

    return (
        <main className="mx-auto max-w-2xl px-4 py-16">
            <h1 className="text-3xl font-semibold">Hello, {user.name}</h1>
            <p className="mt-2 text-muted">{user.email}</p>
            <Button
                className="mt-8"
                variant="quiet"
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
