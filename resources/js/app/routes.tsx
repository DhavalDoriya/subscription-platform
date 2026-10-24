import { Navigate, Route, Routes } from 'react-router-dom';
import { ForgotPasswordPage } from '@/areas/auth/ForgotPasswordPage';
import { LoginPage } from '@/areas/auth/LoginPage';
import { RegisterPage } from '@/areas/auth/RegisterPage';
import { ResetPasswordPage } from '@/areas/auth/ResetPasswordPage';
import { VerifyEmailPage } from '@/areas/auth/VerifyEmailPage';
import { AdminLayout } from '@/areas/admin/AdminLayout';
import { AdminsPage } from '@/areas/admin/AdminsPage';
import { AuditLogPage } from '@/areas/admin/AuditLogPage';
import { CustomersPage } from '@/areas/admin/CustomersPage';
import { DashboardPage } from '@/areas/admin/DashboardPage';
import { InvoicesPage } from '@/areas/admin/InvoicesPage';
import { ProvidersPage } from '@/areas/admin/ProvidersPage';
import { SettingsPage } from '@/areas/admin/SettingsPage';
import { SubscriptionsPage } from '@/areas/admin/SubscriptionsPage';
import { PlansAdminPage } from '@/areas/admin/PlansAdminPage';
import { EmailLogsPage } from '@/areas/admin/EmailLogsPage';
import { EmailTemplateEditPage } from '@/areas/admin/EmailTemplateEditPage';
import { EmailTemplatesPage } from '@/areas/admin/EmailTemplatesPage';
import { WebhooksPage } from '@/areas/admin/WebhooksPage';
import { StorefrontEditorPage } from '@/areas/provider/StorefrontEditorPage';
import { InvoicesPage as ProviderInvoicesPage } from '@/areas/provider/InvoicesPage';
import { PaymentsPage } from '@/areas/provider/PaymentsPage';
import { PlansPage } from '@/areas/provider/PlansPage';
import { SubscribersPage } from '@/areas/provider/SubscribersPage';
import { StripeRefreshPage, StripeReturnPage } from '@/areas/provider/StripeReturnPages';
import { ProviderLayout } from '@/areas/provider/ProviderLayout';
import { ProviderOverviewPage } from '@/areas/provider/ProviderOverviewPage';
import { ProviderSettingsPage } from '@/areas/provider/ProviderSettingsPage';
import { RegisterProviderPage } from '@/areas/provider/RegisterProviderPage';
import { AccountPage } from '@/areas/customer/AccountPage';
import { FixPaymentPage } from '@/areas/customer/FixPaymentPage';
import { ProfilePage } from '@/areas/customer/ProfilePage';
import { GuestOnly, RequireAuth } from '@/lib/auth';

// Areas share one SPA and are split by route + role guard:
// /admin/* (platform admin), /dashboard/* (provider), /account/* (customer),
// storefront (resolved from the subdomain).
export function AppRoutes() {
    return (
        <Routes>
            <Route
                path="/login"
                element={
                    <GuestOnly>
                        <LoginPage />
                    </GuestOnly>
                }
            />
            <Route
                path="/register"
                element={
                    <GuestOnly>
                        <RegisterPage />
                    </GuestOnly>
                }
            />
            <Route path="/forgot-password" element={<ForgotPasswordPage />} />
            <Route path="/reset-password" element={<ResetPasswordPage />} />
            <Route path="/verify-email" element={<VerifyEmailPage />} />
            <Route
                path="/admin"
                element={
                    <RequireAuth roles={['admin', 'super_admin']}>
                        <AdminLayout />
                    </RequireAuth>
                }
            >
                <Route index element={<DashboardPage />} />
                <Route path="providers" element={<ProvidersPage />} />
                <Route path="customers" element={<CustomersPage />} />
                <Route
                    path="admins"
                    element={
                        <RequireAuth roles={['super_admin']}>
                            <AdminsPage />
                        </RequireAuth>
                    }
                />
                <Route path="plans" element={<PlansAdminPage />} />
                <Route path="subscriptions" element={<SubscriptionsPage />} />
                <Route path="invoices" element={<InvoicesPage />} />
                <Route path="email-templates" element={<EmailTemplatesPage />} />
                <Route path="email-templates/:id" element={<EmailTemplateEditPage />} />
                <Route path="email-log" element={<EmailLogsPage />} />
                <Route path="webhooks" element={<WebhooksPage />} />
                <Route path="audit-log" element={<AuditLogPage />} />
                <Route path="settings" element={<SettingsPage />} />
            </Route>
            <Route
                path="/become-a-provider"
                element={
                    <RequireAuth>
                        <RegisterProviderPage />
                    </RequireAuth>
                }
            />
            <Route
                path="/dashboard"
                element={
                    <RequireAuth roles={['provider_owner']}>
                        <ProviderLayout />
                    </RequireAuth>
                }
            >
                <Route index element={<ProviderOverviewPage />} />
                <Route path="subscribers" element={<SubscribersPage />} />
                <Route path="payments" element={<PaymentsPage />} />
                <Route path="invoices" element={<ProviderInvoicesPage />} />
                <Route path="plans" element={<PlansPage />} />
                <Route path="storefront" element={<StorefrontEditorPage />} />
                <Route path="settings" element={<ProviderSettingsPage />} />
            </Route>
            <Route
                path="/dashboard/stripe/return"
                element={
                    <RequireAuth roles={['provider_owner']}>
                        <StripeReturnPage />
                    </RequireAuth>
                }
            />
            <Route
                path="/dashboard/stripe/refresh"
                element={
                    <RequireAuth roles={['provider_owner']}>
                        <StripeRefreshPage />
                    </RequireAuth>
                }
            />
            <Route
                path="/account"
                element={
                    <RequireAuth>
                        <AccountPage />
                    </RequireAuth>
                }
            />
            <Route
                path="/account/profile"
                element={
                    <RequireAuth>
                        <ProfilePage />
                    </RequireAuth>
                }
            />
            <Route
                path="/account/fix-payment"
                element={
                    <RequireAuth>
                        <FixPaymentPage />
                    </RequireAuth>
                }
            />
            <Route path="*" element={<Navigate to="/login" replace />} />
        </Routes>
    );
}
