import { expect, test, type Page } from '@playwright/test';

/**
 * Real-browser sweep of Phase 5 without a real Stripe account. Everything that reads our own database runs for real
 * against seeded demo data; actions that need Stripe (refund, cancel) are expected to fail with the clear "Stripe is not
 * configured" message, which proves the error reaches the screen. The paid happy path needs test keys (see WORK-PLAN).
 *
 * Serve with `npm run build && npm run e2e:serve`, then seed:
 *   php artisan app:create-admin e2e-admin@example.com --name="E2E Admin" --password='Password123!'
 *   php artisan db:seed --class=DemoBillingSeeder
 */
const adminEmail = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const password = 'Password123!';
const port = new URL(process.env.E2E_BASE_URL ?? 'http://localhost:8000').port || '8000';

function watch(page: Page, problems: string[]) {
    const expected = [401, 409, 503]; // signed-out refresh, "already subscribed", "Stripe is not configured"
    page.on('console', (m) => m.type() === 'error' && !/status of (401|409|503)/.test(m.text()) && problems.push(`console: ${m.text()}`));
    page.on('requestfailed', (r) => problems.push(`failed: ${r.url()}`));
    page.on('response', (r) => r.status() >= 400 && !expected.includes(r.status()) && problems.push(`${r.status()} ${r.url()}`));
}

async function signIn(page: Page, email: string) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
}

test('admin sees subscriptions and invoices across providers', async ({ page }) => {
    const problems: string[] = [];
    watch(page, problems);

    await signIn(page, adminEmail);
    const nav = page.getByRole('navigation', { name: 'Admin' });

    await nav.getByRole('link', { name: 'Subscriptions' }).click();
    const row = page.getByRole('row', { name: /Casey Customer/ });
    await expect(row).toContainText('Demo Gym');
    await expect(row).toContainText('$19.00 / month');
    await expect(row.getByText('Active')).toBeVisible();
    await page.screenshot({ path: 'test-results/admin-subscriptions.png', fullPage: true });

    await page.getByLabel('Status').selectOption('canceled');
    await expect(page.getByText('No subscriptions found')).toBeVisible();

    await nav.getByRole('link', { name: 'Invoices' }).click();
    await expect(page.getByRole('row', { name: /DEMO-0001/ })).toContainText('Paid');
    await page.screenshot({ path: 'test-results/admin-invoices.png', fullPage: true });

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/login$/);
    expect(problems).toEqual([]);
});

test('provider reads their numbers and subscribers, and a refund surfaces the Stripe error', async ({ page }) => {
    const problems: string[] = [];
    watch(page, problems);

    await signIn(page, 'demo-owner@example.com');
    await expect(page).toHaveURL(/\/dashboard$/);

    const numbers = page.getByRole('region', { name: 'Key numbers' });
    await expect(numbers).toContainText('$19.00');
    await expect(numbers.getByText('Active subscribers').locator('..')).toContainText('1');
    await page.screenshot({ path: 'test-results/provider-overview-metrics.png', fullPage: true });

    const nav = page.getByRole('navigation', { name: 'Provider' });
    await nav.getByRole('link', { name: 'Subscribers' }).click();
    await expect(page.getByRole('row', { name: /Casey Customer/ })).toContainText('Demo Pro');
    await page.getByRole('searchbox', { name: 'Search subscribers' }).fill('nobody-here');
    await expect(page.getByText('No subscribers yet')).toBeVisible();
    await page.getByRole('searchbox', { name: 'Search subscribers' }).fill('casey');
    await page.getByRole('button', { name: 'View Casey Customer' }).click();

    const detail = page.getByRole('dialog', { name: 'Casey Customer' });
    await expect(detail.getByText('DEMO-0001')).toBeVisible();
    await expect(detail.getByText('Your card has insufficient funds.')).toBeVisible();
    await page.screenshot({ path: 'test-results/provider-subscriber-detail.png', fullPage: true });

    await detail.getByRole('button', { name: 'Refund $19.00' }).click();
    const refund = page.getByRole('dialog', { name: 'Refund payment' });
    await refund.getByLabel('Amount').fill('25.00');
    await refund.getByRole('button', { name: 'Refund' }).click();
    await expect(refund.getByText(/up to \$19\.00/)).toBeVisible(); // too much: caught before the server
    await refund.getByLabel('Amount').fill('5.00');
    await refund.getByRole('button', { name: 'Refund' }).click();
    await expect(refund.getByRole('alert')).toContainText('Stripe is not configured yet.');
    await page.keyboard.press('Escape');
    await page.keyboard.press('Escape');

    await nav.getByRole('link', { name: 'Payments' }).click();
    await expect(page.getByRole('row', { name: /Failed/ })).toContainText('insufficient_funds');
    await expect(page.getByRole('row', { name: /Paid/ })).toBeVisible();
    await nav.getByRole('link', { name: 'Invoices' }).click();
    await expect(page.getByRole('row', { name: /DEMO-0001/ })).toBeVisible();

    await page.getByRole('button', { name: 'Sign out' }).click();
    expect(problems).toEqual([]);
});

test('customer sees their subscription, invoices and card, and cancelling surfaces the Stripe error', async ({ page }) => {
    const problems: string[] = [];
    watch(page, problems);

    await signIn(page, 'demo-customer@example.com');
    await expect(page).toHaveURL(/\/account$/);

    const sub = page.getByRole('article', { name: /Demo Pro/ });
    await expect(sub).toContainText('Demo Gym');
    await expect(sub).toContainText('$19.00 / month');
    await expect(page.getByRole('row', { name: /DEMO-0001/ })).toContainText('Paid');
    await expect(page.getByRole('listitem', { name: /Visa ending 4242/ })).toContainText('Default');
    await page.screenshot({ path: 'test-results/customer-portal.png', fullPage: true });

    await sub.getByRole('button', { name: 'Cancel subscription' }).click();
    const dialog = page.getByRole('alertdialog');
    await dialog.getByRole('button', { name: 'Cancel at period end' }).click();
    await expect(dialog.getByRole('alert')).toContainText('Stripe is not configured yet.');
    await dialog.getByRole('button', { name: 'Keep subscription' }).click();

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/login$/);
    expect(problems).toEqual([]);
});

test('storefront: a visitor signs in on the storefront and cannot subscribe twice', async ({ page }) => {
    const problems: string[] = [];
    watch(page, problems);

    await page.goto(`http://demo-gym.platform.test:${port}/`);
    await expect(page.getByRole('heading', { name: 'Welcome to Demo Gym' })).toBeVisible();
    await page.screenshot({ path: 'test-results/storefront-plans.png', fullPage: true });

    await page.getByRole('button', { name: 'Subscribe' }).click();
    const dialog = page.getByRole('dialog', { name: 'Sign in to subscribe' });
    await expect(dialog.getByRole('tab', { name: 'Create account' })).toBeVisible();
    await dialog.getByLabel('Email').fill('demo-customer@example.com');
    await dialog.getByLabel('Password').fill(password);
    await dialog.getByRole('button', { name: 'Sign in' }).click();

    await expect(page.getByRole('button', { name: 'Continue as Casey Customer' })).toBeVisible();
    await page.screenshot({ path: 'test-results/storefront-continue-as.png', fullPage: true });
    await page.getByRole('button', { name: 'Continue as Casey Customer' }).click();
    await expect(page.getByRole('alert')).toContainText('You already have a subscription to this plan.');
    expect(problems).toEqual([]);
});
