import { Eye } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { endImpersonation, useAuthStore } from '@/lib/auth';

/** Shown on every page while an admin is acting as another user, with a way back. */
export function ImpersonationBanner() {
    const user = useAuthStore((s) => s.user);
    const impersonator = useAuthStore((s) => s.impersonator);
    const navigate = useNavigate();

    if (!user || !impersonator) return null;

    return (
        <div role="status" className="flex flex-wrap items-center justify-center gap-3 bg-amber-100 px-4 py-2 text-sm text-amber-950">
            <Eye aria-hidden className="size-4" />
            <span>
                Viewing as <strong>{user.name}</strong> ({user.email}). Signed in as {impersonator.name}.
            </span>
            <Button
                size="sm"
                variant="outline"
                onClick={async () => {
                    await endImpersonation();
                    navigate('/admin/customers', { replace: true });
                }}
            >
                Exit
            </Button>
        </div>
    );
}
