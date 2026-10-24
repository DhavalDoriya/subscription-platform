import { expect, test } from '@playwright/test';

/** Real-browser sweep of the auth flow. Requires: app served, DB migrated, `E2E_ADMIN_EMAIL/PASSWORD` seeded. */
test('register, sign out, sign in, stay signed in after reload, no console or network errors', async ({ page }) => {
    const problems: string[] = [];
    page.on('console', (m) => m.type() === 'error' && !m.text().includes('401') && problems.push(m.text()));
    page.on('requestfailed', (r) => problems.push(`failed: ${r.url()}`));
    page.on('response', (r) => r.status() >= 500 && problems.push(`${r.status()} ${r.url()}`));

    const email = `e2e-${Date.now()}@example.com`;

    await page.goto('/register');
    await page.getByLabel('Full name').fill('E2E User');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('Password123!');
    await page.getByLabel('Confirm password').fill('Password123!');
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page.getByRole('heading', { name: 'Hello, E2E User' })).toBeVisible();

    await page.reload(); // session restored from the refresh cookie
    await expect(page.getByRole('heading', { name: 'Hello, E2E User' })).toBeVisible();

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();

    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('wrong-password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('alert')).toContainText('Invalid email or password.');

    await page.getByLabel('Password').fill('Password123!');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Hello, E2E User' })).toBeVisible();

    await page.screenshot({ path: 'test-results/account.png', fullPage: true });
    expect(problems).toEqual([]);
});

test('login page renders', async ({ page }) => {
    await page.goto('/login');
    await page.screenshot({ path: 'test-results/login.png', fullPage: true });
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
});
