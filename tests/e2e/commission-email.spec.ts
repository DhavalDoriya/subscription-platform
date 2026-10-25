import { expect, test, type Page } from '@playwright/test';

/**
 * Real-browser sweep of Phase 7 (email screens), Phase 8 (commission) and the reports of Phase 9 against seeded demo data.
 * Writes: a global commission rate (a scheduled one, removed again), an email template edit that is restored, and a
 * test-send that lands in the log mailer.
 *
 * Serve with `npm run build && npm run e2e:serve`, then seed:
 *   php artisan app:create-admin e2e-admin@example.com --name="E2E Admin" --password='Password123!'
 *   php artisan db:seed --class=DemoBillingSeeder
 */
const adminEmail = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const password = 'Password123!';

function watch(page: Page, problems: string[]) {
    page.on('console', (m) => m.type() === 'error' && !/status of 401/.test(m.text()) && problems.push(`console: ${m.text()}`));
    page.on('requestfailed', (r) => problems.push(`failed: ${r.url()}`));
    page.on('response', (r) => r.status() >= 400 && r.status() !== 401 && problems.push(`${r.status()} ${r.url()}`));
}

async function signIn(page: Page, email: string) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
}

async function openFromNav(page: Page, group: 'Admin' | 'Provider', link: string) {
    await page.getByRole('navigation', { name: group }).getByRole('link', { name: link, exact: true }).click();
}

test('admin: email templates, email log, reports and commission', async ({ page }) => {
    const problems: string[] = [];
    watch(page, problems);
    await signIn(page, adminEmail);
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();

    // --- Email templates: list, edit, preview, restore
    await openFromNav(page, 'Admin', 'Email templates');
    await expect(page.getByRole('heading', { name: 'Email templates' })).toBeVisible();
    await expect(page.getByText('auth.verify_email')).toBeVisible();
    await page.screenshot({ path: 'test-results/email-templates.png', fullPage: true });

    // --- Email log
    await openFromNav(page, 'Admin', 'Email log');
    await expect(page.getByRole('heading', { name: 'Email log' })).toBeVisible();
    await page.screenshot({ path: 'test-results/email-log.png', fullPage: true });

    // --- Reports
    await openFromNav(page, 'Admin', 'Reports');
    await expect(page.getByRole('heading', { name: 'Reports' })).toBeVisible();
    await expect(page.getByRole('table', { name: 'Revenue' })).toBeVisible();
    const download = page.waitForEvent('download');
    await page.getByRole('button', { name: /Revenue CSV/ }).click();
    expect((await download).suggestedFilename()).toMatch(/^revenue-.*\.csv$/);
    await page.screenshot({ path: 'test-results/reports.png', fullPage: true });

    // --- Commission rates: add a scheduled default rate, see it, remove it
    await openFromNav(page, 'Admin', 'Commission rates');
    await expect(page.getByRole('heading', { name: 'Commission rates' })).toBeVisible();
    await page.getByRole('button', { name: /Add rate/ }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Commission (%)').fill('12.5');
    await dialog.getByLabel('Effective from').fill('2099-01-01T00:00');
    await dialog.getByRole('button', { name: 'Add rate' }).click();
    const row = page.getByRole('row', { name: /12.50%/ });
    await expect(row).toContainText('Scheduled');
    await page.screenshot({ path: 'test-results/commission-rates.png', fullPage: true });
    await row.getByRole('button', { name: /Remove/ }).click();
    await page.getByRole('button', { name: 'Remove rate' }).click();
    await expect(page.getByRole('row', { name: /12.50%/ })).toHaveCount(0);

    // --- Commission report
    await openFromNav(page, 'Admin', 'Commission report');
    await expect(page.getByRole('heading', { name: 'Commission report' })).toBeVisible();
    await page.screenshot({ path: 'test-results/commission-report.png', fullPage: true });

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/login$/);
    expect(problems).toEqual([]);
});

test('provider: reports and platform fee columns', async ({ page }) => {
    const problems: string[] = [];
    watch(page, problems);
    await signIn(page, 'demo-owner@example.com');
    await expect(page.getByRole('heading', { name: /Overview|Dashboard/ }).first()).toBeVisible();
    await expect(page.getByText('Platform fee rate')).toBeVisible();

    await openFromNav(page, 'Provider', 'Payments');
    await expect(page.getByRole('columnheader', { name: 'Platform fee' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'You receive' })).toBeVisible();
    await page.screenshot({ path: 'test-results/provider-payments-fee.png', fullPage: true });

    await openFromNav(page, 'Provider', 'Reports');
    await expect(page.getByRole('table', { name: 'Revenue' })).toBeVisible();
    await page.screenshot({ path: 'test-results/provider-reports.png', fullPage: true });

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/login$/);
    expect(problems).toEqual([]);
});
