import { LogOut } from 'lucide-react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { logout, useAuthStore } from '@/lib/auth';
import { cn } from '@/lib/utils';
import { adminNav } from './nav';

export function AdminLayout() {
    const user = useAuthStore((s) => s.user);
    const navigate = useNavigate();

    // Signing out clears the user a moment before the redirect lands.
    if (!user) return null;

    return (
        <div className="grid min-h-screen lg:grid-cols-[16rem_minmax(0,1fr)]">
            <aside className="bg-sidebar text-sidebar-foreground lg:sticky lg:top-0 lg:h-screen">
                <div className="px-6 py-6 text-lg font-semibold text-white">Billing Platform</div>
                <nav aria-label="Admin" className="flex flex-wrap gap-x-6 gap-y-2 px-3 pb-4 lg:block lg:space-y-6">
                    {adminNav.map((group) => {
                        const items = group.items.filter((item) => !item.roles || item.roles.some((role) => user.roles.includes(role)));

                        return (
                            <div key={group.label}>
                                <h2 className="px-3 pb-1 text-xs font-medium uppercase tracking-wider text-sidebar-foreground/60">{group.label}</h2>
                                <ul>
                                    {items.map((item) => (
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
                            </div>
                        );
                    })}
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
