import { BarChart3, CreditCard, Globe, Layers, LayoutDashboard, LogOut, Receipt, Settings, Users } from 'lucide-react';
import { useEffect } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { ApiError } from '@/lib/api';
import { logout, useAuthStore } from '@/lib/auth';
import { cn } from '@/lib/utils';
import { useMyProvider } from './services/provider';

const nav = [
    { label: 'Overview', to: '/dashboard', icon: LayoutDashboard, end: true },
    { label: 'Subscribers', to: '/dashboard/subscribers', icon: Users, end: false },
    { label: 'Payments', to: '/dashboard/payments', icon: CreditCard, end: false },
    { label: 'Reports', to: '/dashboard/reports', icon: BarChart3, end: false },
    { label: 'Invoices', to: '/dashboard/invoices', icon: Receipt, end: false },
    { label: 'Plans', to: '/dashboard/plans', icon: Layers, end: false },
    { label: 'Storefront', to: '/dashboard/storefront', icon: Globe, end: false },
    { label: 'Settings', to: '/dashboard/settings', icon: Settings, end: false },
];

export function ProviderLayout() {
    const user = useAuthStore((s) => s.user);
    const navigate = useNavigate();
    const { data: provider, error } = useMyProvider();

    // A provider_owner role without a provider (or a stale token) goes back to registration.
    useEffect(() => {
        if (error instanceof ApiError && error.status === 404) navigate('/become-a-provider', { replace: true });
    }, [error, navigate]);

    if (!user) return null;

    return (
        <div className="grid min-h-screen lg:grid-cols-[15rem_minmax(0,1fr)]">
            <aside className="bg-sidebar text-sidebar-foreground lg:sticky lg:top-0 lg:h-screen lg:overflow-y-auto">
                <div className="px-6 py-6 text-lg font-semibold text-white">{provider?.name ?? <Skeleton className="h-6 w-32" />}</div>
                <nav aria-label="Provider" className="px-3 pb-4">
                    <ul>
                        {nav.map((item) => (
                            <li key={item.to}>
                                <NavLink
                                    to={item.to}
                                    end={item.end}
                                    className={({ isActive }) =>
                                        cn(
                                            'flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                                            isActive && 'bg-sidebar-accent text-sidebar-accent-foreground',
                                        )
                                    }
                                >
                                    <item.icon aria-hidden className="size-4" />
                                    {item.label}
                                </NavLink>
                            </li>
                        ))}
                    </ul>
                </nav>
            </aside>

            <div className="flex min-w-0 flex-col">
                <header className="flex items-center justify-end gap-4 border-b bg-card px-6 py-3">
                    <div className="text-right leading-tight">
                        <p className="text-sm font-medium">{user.name}</p>
                        <p className="text-xs text-muted-foreground">{user.email}</p>
                    </div>
                    <Button
                        variant="outline"
                        onClick={async () => {
                            await logout();
                            navigate('/', { replace: true });
                        }}
                    >
                        <LogOut aria-hidden /> Sign out
                    </Button>
                </header>
                <main className="flex-1 px-6 py-8">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
