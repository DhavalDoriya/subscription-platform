import { expect, test } from '@playwright/test';

/** Real-browser sweep of provider onboarding (FR-P1, FR-P2, FR-P7). Serve with `npm run build && npm run e2e:serve`. */
test('a customer becomes a provider, sees the dashboard, edits settings, and lands on the dashboard when signing back in', async ({ page }) => {
    const problems: string[] = [];
    page.on('console', (m) => m.type() === 'error' && !/status of (401|409|422)/.test(m.text()) && problems.push(`console: ${m.text()}`));
    page.on('requestfailed', (r) => problems.push(`failed: ${r.url()}`));
    page.on('response', (r) => r.status() >= 400 && ![401, 409, 422].includes(r.status()) && problems.push(`${r.status()} ${r.url()}`));

    const stamp = Date.now();
    const email = `e2e-prov-${stamp}@example.com`;
    const slug = `shop-${stamp}`;

    await page.goto('/register');
    await page.getByLabel('Full name').fill('Pat Provider');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('Password123!');
    await page.getByLabel('Confirm password').fill('Password123!');
    await page.getByRole('button', { name: 'Create account' }).click();
    await page.getByRole('link', { name: 'Become a provider' }).click();
    await expect(page.getByRole('heading', { name: 'Become a provider' })).toBeVisible();

    // The address is checked live, and a reserved word is refused.
    await page.getByLabel('Address').fill('www');
    await expect(page.getByText('This address is reserved.')).toBeVisible();
    await page.getByLabel('Address').fill(slug.toUpperCase());
    await expect(page.getByLabel('Address')).toHaveValue(slug);
    await expect(page.getByText(/is available\./)).toBeVisible();

    await page.getByLabel('Business name').fill('Shop Fitness');
    await page.getByLabel('Category').fill('fitness');
    await page.getByLabel('Country').fill('us');
    await expect(page.getByLabel('Currency')).toBeEnabled();
    await page.screenshot({ path: 'test-results/provider-register.png', fullPage: true });
    await page.getByRole('button', { name: 'Create provider account' }).click();

    // Auto-approve is on by default, so the account is approved straight away and the role is already in the session.
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByRole('status', { name: 'Account status' })).toContainText('Approved');
    await expect(page.getByRole('navigation', { name: 'Provider' })).toBeVisible();
    await page.screenshot({ path: 'test-results/provider-dashboard.png', fullPage: true });

    await page.getByRole('navigation', { name: 'Provider' }).getByRole('link', { name: 'Settings' }).click();
    await page.getByLabel('Business name').fill('Shop Gym');
    await page.getByLabel('Terms of service URL').fill('https://shop.test/terms');
    await page.getByLabel('Brand colour').fill('#3B5BDB');
    await page.getByRole('button', { name: 'Save settings' }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();

    await page.reload(); // persisted, and the session survives a reload
    await expect(page.getByLabel('Business name')).toHaveValue('Shop Gym');
    await expect(page.getByLabel('Terms of service URL')).toHaveValue('https://shop.test/terms');
    await expect(page.getByLabel('Brand colour')).toHaveValue('#3b5bdb');

    // A bad link is rejected by the server and shown next to the field.
    await page.getByLabel('Privacy policy URL').fill('not a url');
    await page.getByRole('button', { name: 'Save settings' }).click();
    await expect(page.getByLabel('Privacy policy URL')).toHaveAccessibleDescription(/valid URL/i);

    // Signing out and back in lands on the provider dashboard, not the customer account.
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('Password123!');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByText('Shop Gym').first()).toBeVisible();

    expect(problems).toEqual([]);
});
