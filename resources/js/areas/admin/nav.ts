import {
    CreditCard,
    BarChart3,
    Percent,
    Receipt,
    FileText,
    Layers,
    Mail,
    MailCheck,
    LayoutDashboard,
    ScrollText,
    Settings,
    ShieldCheck,
    Store,
    Users,
    Webhook,
    type LucideIcon,
} from 'lucide-react';
import type { Role } from '@/lib/auth';

export interface NavItem {
    label: string;
    to: string;
    icon: LucideIcon;
    /** Hidden unless the user has one of these roles. */
    roles?: Role[];
    end?: boolean;
}

export interface NavGroup {
    label: string;
    items: NavItem[];
}

/** Sidebar grouped by domain (build-admin-panel skill). */
export const adminNav: NavGroup[] = [
    { label: 'Overview', items: [{ label: 'Dashboard', to: '/admin', icon: LayoutDashboard, end: true }] },
    {
        label: 'Marketplace',
        items: [
            { label: 'Providers', to: '/admin/providers', icon: Store },
            { label: 'Plans', to: '/admin/plans', icon: Layers },
        ],
    },
    {
        label: 'Billing',
        items: [
            { label: 'Subscriptions', to: '/admin/subscriptions', icon: CreditCard },
            { label: 'Invoices', to: '/admin/invoices', icon: FileText },
            { label: 'Reports', to: '/admin/reports', icon: BarChart3 },
            { label: 'Commission rates', to: '/admin/commission', icon: Percent },
            { label: 'Commission report', to: '/admin/commission-report', icon: Receipt },
        ],
    },
    {
        label: 'Email',
        items: [
            { label: 'Email templates', to: '/admin/email-templates', icon: Mail },
            { label: 'Email log', to: '/admin/email-log', icon: MailCheck },
        ],
    },
    {
        label: 'People',
        items: [
            { label: 'Customers', to: '/admin/customers', icon: Users },
            { label: 'Admins', to: '/admin/admins', icon: ShieldCheck, roles: ['super_admin'] },
        ],
    },
    {
        label: 'System',
        items: [
            { label: 'Audit log', to: '/admin/audit-log', icon: ScrollText },
            { label: 'Webhooks', to: '/admin/webhooks', icon: Webhook },
            { label: 'Settings', to: '/admin/settings', icon: Settings },
        ],
    },
];
