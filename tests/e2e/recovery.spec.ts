import { expect, test, type Page } from '@playwright/test';

/**
 * Real-browser sweep of Phase 6 (payment retry and dunning) against seeded data, no Stripe account needed. "Retry now"
 * needs Stripe, so it is expected to surface an error toast; the screen must survive it.
 *
 * Serve with `npm run build && npm run e2e:serve`, then seed (on a fresh database: the resolve write in the last test
 * is not undone, so re-run `php artisan migrate:fresh --seed --force` and these seeders before running this spec again):
 *   php artisan app:create-admin e2e-admin@example.com --name="E2E Admin" --password='Password123!'
 *   php artisan db:seed --class=DemoBillingSeeder
 *   php artisan db:seed --class=DemoDunningSeeder
 */
const adminEmail = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const password = 'Password123!';

function watch(page: Page, problems: string[]) {
    const expected = [401, 409, 422, 500, 502, 503]; // signed-out refresh, and the failed "Retry now" without a Stripe key
    page.on('console', (m) => m.type() === 'error' && !/status of (401|409|422|500|502|503)/.test(m.text()) && problems.push(`console: ${m.text()}`));
    page.on('requestfailed', (r) => problems.push(`failed: ${r.url()}`));
    page.on('response', (r) => r.status() >= 400 && !expected.includes(r.status()) && problems.push(`${r.status()} ${r.url()}`));
}

async function signIn(page: Page, email: string) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
}

async function signOut(page: Page) {
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/login$/);
}

test('customer sees the payment problem and why the card was declined', async ({ page }) => {
    const problems: string[] = [];
    watch(page, problems);

    await signIn(page, 'recovery-customer@example.com');
    await expect(page).toHaveURL(/\/account$/);

    const banner = page.getByRole('region', { name: 'Payment problems' });
    await expect(banner).toContainText('Recovery Plus with Recovery Gym');
    await expect(banner).toContainText('$25.00 is overdue');
    await page.screenshot({ path: 'test-results/recovery-customer-account.png', fullPage: true });

    await banner.getByRole('link', { name: 'Fix payment' }).click();
    await expect(page).toHaveURL(/\/account\/fix-payment$/);
    await expect(page.getByRole('heading', { name: 'Fix your payment' })).toBeVisible();
    await expect(page.getByText('$25.00 overdue')).toBeVisible();
    await expect(page.getByText('Your card has insufficient funds.')).toBeVisible();
    await expect(page.getByText(/We’ll try again on/)).toBeVisible();
    await expect(page.getByRole('region', { name: 'Payment methods' })).toBeVisible();
    await page.screenshot({ path: 'test-results/recovery-fix-payment.png', fullPage: true });

    await page.goto('/account');
    await signOut(page);
    expect(problems).toEqual([]);
});

test('admin sees payment recovery on the dashboard and edits the dunning settings', async ({ page }) => {
    const problems: string[] = [];
    watch(page, problems);

    await signIn(page, adminEmail);
    const panel = page
        .getByRole('region', { name: 'Payment recovery' })
        .or(page.locator('section', { has: page.getByRole('heading', { name: 'Payment recovery' }) }));
    await expect(panel.first()).toContainText('Failed invoices');
    await expect(panel.first()).toContainText('Recovery rate');
    await page.screenshot({ path: 'test-results/recovery-admin-dashboard.png', fullPage: true });

    await page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Settings' }).click();
    const group = page.getByRole('region', { name: 'Payment recovery' });
    await expect(group.getByLabel('Retry after (days)')).toHaveValue('1, 3, 5, 7');
    const grace = group.getByLabel('Grace period (days)');
    await expect(grace).toHaveValue('7');
    await grace.fill('9');
    await page.getByRole('button', { name: 'Save settings' }).click();
    await expect(page.getByText('Settings saved')).toBeVisible();
    await page.reload();
    await expect(page.getByRole('region', { name: 'Payment recovery' }).getByLabel('Grace period (days)')).toHaveValue('9');
    await page.screenshot({ path: 'test-results/recovery-admin-settings.png', fullPage: true });

    // Put it back.
    await page.getByRole('region', { name: 'Payment recovery' }).getByLabel('Grace period (days)').fill('7');
    await page.getByRole('button', { name: 'Save settings' }).click();
    await expect(page.getByText('Settings saved')).toBeVisible();

    await signOut(page);
    expect(problems).toEqual([]);
});

test('provider reads the recovery numbers, inspects the history, retries and resolves the invoice', async ({ page }) => {
    const problems: string[] = [];
    watch(page, problems);

    await signIn(page, 'recovery-owner@example.com');
    await expect(page).toHaveURL(/\/dashboard$/);

    const recovery = page.getByRole('region', { name: 'Payment recovery' });
    await expect(recovery.getByText('Failed invoices').locator('..')).toContainText('1');
    await page.screenshot({ path: 'test-results/recovery-provider-overview.png', fullPage: true });

    await page.getByRole('navigation', { name: 'Provider' }).getByRole('link', { name: 'Invoices' }).click();
    await expect(page.getByRole('heading', { name: 'Invoices' })).toBeVisible();
    await page.getByLabel('Recovery').selectOption({ label: 'Needs attention' });
    const row = page.getByRole('row', { name: /RECOVERY-0001/ });
    await expect(row).toContainText('Retrying');
    await expect(row).toContainText('Next retry');
    await page.screenshot({ path: 'test-results/recovery-provider-invoices.png', fullPage: true });

    await row.getByRole('button', { name: 'Show recovery history for RECOVERY-0001' }).click();
    const history = page.getByRole('dialog', { name: /Recovery history for RECOVERY-0001/ });
    await expect(history.getByText('Attempt 1')).toBeVisible();
    await expect(history.getByText('insufficient_funds')).toBeVisible();
    await page.screenshot({ path: 'test-results/recovery-provider-history.png', fullPage: true });
    await page.keyboard.press('Escape');
    await expect(history).toBeHidden();

    // No Stripe key on the e2e server: an error toast, and the page keeps working.
    await row.getByRole('button', { name: 'Retry invoice RECOVERY-0001 now' }).click();
    await expect(page.locator('[data-sonner-toast]').first()).toBeVisible();
    await expect(row).toBeVisible();

    await row.getByRole('button', { name: 'Mark invoice RECOVERY-0001 resolved' }).click();
    const confirm = page.getByRole('alertdialog').or(page.getByRole('dialog', { name: 'Mark this invoice resolved?' }));
    await confirm.first().getByRole('button', { name: 'Mark resolved' }).click();
    await expect(page.getByText('Marked resolved')).toBeVisible();

    await page.getByLabel('Recovery').selectOption({ label: 'All invoices' });
    await expect(page.getByRole('row', { name: /RECOVERY-0001/ })).toContainText('Resolved');
    await page.screenshot({ path: 'test-results/recovery-provider-resolved.png', fullPage: true });

    await signOut(page);
    expect(problems).toEqual([]);
});
