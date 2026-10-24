import { Navigate, Route, Routes } from 'react-router-dom';
import { ForgotPasswordPage } from '@/areas/auth/ForgotPasswordPage';
import { LoginPage } from '@/areas/auth/LoginPage';
import { RegisterPage } from '@/areas/auth/RegisterPage';
import { ResetPasswordPage } from '@/areas/auth/ResetPasswordPage';
import { VerifyEmailPage } from '@/areas/auth/VerifyEmailPage';
import { AccountPage } from '@/areas/customer/AccountPage';
import { GuestOnly, RequireAuth } from '@/lib/auth';

// Areas share one SPA and are split by route + role guard:
// /admin/* (platform admin), /dashboard/* (provider), /account/* (customer),
// storefront (resolved from the subdomain).
export function AppRoutes() {
    return (
        <Routes>
            <Route path="/login" element={<GuestOnly><LoginPage /></GuestOnly>} />
            <Route path="/register" element={<GuestOnly><RegisterPage /></GuestOnly>} />
            <Route path="/forgot-password" element={<ForgotPasswordPage />} />
            <Route path="/reset-password" element={<ResetPasswordPage />} />
            <Route path="/verify-email" element={<VerifyEmailPage />} />
            <Route path="/account" element={<RequireAuth><AccountPage /></RequireAuth>} />
            <Route path="*" element={<Navigate to="/login" replace />} />
        </Routes>
    );
}
