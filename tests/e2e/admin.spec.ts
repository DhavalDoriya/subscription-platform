import { expect, test } from '@playwright/test';

/**
 * Real-browser sweep of the admin panel (build-admin-panel skill): login, every sidebar page, real writes, logout.
 * Requires the app served, DB migrated, and an admin seeded:
 *   npm run build && npm run e2e:serve   (serves with a higher auth rate limit so the suite is not throttled)
 *   php artisan app:create-admin $E2E_ADMIN_EMAIL --name="E2E Admin" --password=$E2E_ADMIN_PASSWORD
 */
const adminEmail = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const adminPassword = process.env.E2E_ADMIN_PASSWORD ?? 'Password123!';

test('admin signs in, visits every page, activates a provider, impersonates a customer, signs out', async ({ page, request }) => {
    const problems: string[] = [];
    page.on('console', (m) => m.type() === 'error' && !/status of (401|409|422)/.test(m.text()) && problems.push(`console: ${m.text()}`));
    page.on('requestfailed', (r) => problems.push(`failed: ${r.url()}`));
    page.on('response', (r) => r.status() >= 400 && ![401, 409, 422].includes(r.status()) && problems.push(`${r.status()} ${r.url()}`));

    // Arrange: a customer who registered a provider through the API (auto-approved => status "approved").
    const stamp = Date.now();
    const email = `e2e-owner-${stamp}@example.com`;
    const slug = `e2e-${stamp}`;
    const registered = await request.post('/api/v1/auth/register', {
        data: { name: 'Olive Owner', email, password: 'Password123!', password_confirmation: 'Password123!' },
    });
    expect(registered.ok()).toBeTruthy();
    const token = (await registered.json()).data.access_token as string;
    const provider = await request.post('/api/v1/provider/register', {
        headers: { Authorization: `Bearer ${token}` },
        data: { name: `E2E Provider ${stamp}`, slug, business_category: 'fitness', country: 'US', support_email: 'help@e2e.test' },
    });
    expect(provider.status()).toBe(201);

    // Sign in lands on the admin dashboard.
    await page.goto('/login');
    await page.getByLabel('Email').fill(adminEmail);
    await page.getByLabel('Password').fill(adminPassword);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Providers', exact: true })).toBeVisible();
    await page.screenshot({ path: 'test-results/admin-dashboard.png', fullPage: true });

    // Every sidebar page renders its heading.
    const nav = page.getByRole('navigation', { name: 'Admin' });
    for (const [link, heading] of [
        ['Providers', 'Providers'],
        ['Customers', 'Customers'],
        ['Audit log', 'Audit log'],
        ['Settings', 'Settings'],
        ['Dashboard', 'Dashboard'],
    ] as const) {
        await nav.getByRole('link', { name: link }).click();
        await expect(page.getByRole('heading', { name: heading, level: 1 })).toBeVisible();
    }
    await expect(nav.getByRole('link', { name: 'Admins' })).toHaveCount(0); // plain admin, not super admin

    // Write 1: activate the provider from its detail dialog.
    await nav.getByRole('link', { name: 'Providers' }).click();
    await page.getByRole('searchbox', { name: 'Search providers' }).fill(slug);
    const providerRow = page.getByRole('row', { name: new RegExp(slug) });
    await expect(providerRow).toBeVisible();
    await expect(providerRow.getByText('Approved')).toBeVisible();
    await providerRow.getByRole('button', { name: /View/ }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText(email)).toBeVisible();
    await dialog.getByRole('button', { name: 'Activate', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(providerRow.getByText('Active')).toBeVisible();
    await page.screenshot({ path: 'test-results/admin-providers.png', fullPage: true });

    // The change is in the audit log.
    await nav.getByRole('link', { name: 'Audit log' }).click();
    await expect(page.getByText('provider.activated').first()).toBeVisible();

    // Write 2: save a setting, then put it back.
    await nav.getByRole('link', { name: 'Settings' }).click();
    const maxPlans = page.getByLabel('Maximum plans per provider');
    await expect(maxPlans).toHaveValue('10');
    await maxPlans.fill('12');
    await page.getByRole('button', { name: 'Save settings' }).click();
    await expect(page.getByText('Settings saved')).toBeVisible();
    await page.reload();
    await expect(page.getByLabel('Maximum plans per provider')).toHaveValue('12');
    await page.getByLabel('Maximum plans per provider').fill('10');
    await page.getByRole('button', { name: 'Save settings' }).click();
    await expect(page.getByText('Settings saved')).toBeVisible();

    // Impersonate the owner, see the banner on their page, exit back to the admin session.
    await nav.getByRole('link', { name: 'Customers' }).click();
    await page.getByRole('searchbox', { name: 'Search customers' }).fill(email);
    await page
        .getByRole('row', { name: new RegExp(email) })
        .getByRole('button', { name: /Impersonate/ })
        .click();
    await expect(page.getByRole('heading', { name: 'Hello, Olive Owner' })).toBeVisible();
    await expect(page.getByRole('status')).toContainText('Viewing as Olive Owner');
    await page.screenshot({ path: 'test-results/admin-impersonating.png', fullPage: true });
    await page.getByRole('button', { name: 'Exit' }).click();
    await expect(page).toHaveURL(/\/admin\/customers$/);
    await expect(page.getByRole('heading', { name: 'Customers', level: 1 })).toBeVisible();
    await expect(page.getByRole('status')).toHaveCount(0);

    // Sign out.
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
    await page.goto('/admin');
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();

    expect(problems).toEqual([]);
});
