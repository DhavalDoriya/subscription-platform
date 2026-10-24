import { expect, test } from '@playwright/test';

/**
 * Real-browser sweep of Phase 3 without a real Stripe account: the platform has no Stripe key, so connecting is refused
 * with a clear message and publishing is blocked, while everything that lives in our database works end to end.
 * Serve with `npm run build && npm run e2e:serve`, and seed an admin (see admin.spec.ts).
 */
const adminEmail = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const adminPassword = process.env.E2E_ADMIN_PASSWORD ?? 'Password123!';

test('provider drafts a plan and is blocked from publishing until Stripe is connected; admin sees and archives it', async ({ page, request }) => {
    const problems: string[] = [];
    const expected = (url: string) => url.endsWith('/provider/stripe/onboarding');
    page.on('console', (m) => m.type() === 'error' && !/status of (401|409|422|503)/.test(m.text()) && problems.push(`console: ${m.text()}`));
    page.on('requestfailed', (r) => problems.push(`failed: ${r.url()}`));
    page.on(
        'response',
        (r) =>
            r.status() >= 400 &&
            ![401, 409, 422].includes(r.status()) &&
            !(r.status() === 503 && expected(r.url())) &&
            problems.push(`${r.status()} ${r.url()}`),
    );

    const stamp = Date.now();
    const email = `e2e-plans-${stamp}@example.com`;
    const planName = `Gold ${stamp}`;

    const registered = await request.post('/api/v1/auth/register', {
        data: { name: 'Pia Provider', email, password: 'Password123!', password_confirmation: 'Password123!' },
    });
    const token = (await registered.json()).data.access_token as string;
    const created = await request.post('/api/v1/provider/register', {
        headers: { Authorization: `Bearer ${token}` },
        data: { name: `Plans Co ${stamp}`, slug: `plans-${stamp}`, business_category: 'fitness', country: 'US', support_email: 'help@plans.test' },
    });
    expect(created.status()).toBe(201);

    // --- Provider ---
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('Password123!');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);

    const payments = page.getByRole('region', { name: 'Payments' });
    await expect(payments).toContainText('Connect Stripe to accept payments');
    await payments.getByRole('button', { name: 'Connect Stripe' }).click();
    await expect(payments.getByRole('alert')).toContainText('Stripe is not configured yet.');
    await page.screenshot({ path: 'test-results/provider-stripe-card.png', fullPage: true });

    await page.getByRole('navigation', { name: 'Provider' }).getByRole('link', { name: 'Plans' }).click();
    await expect(page.getByText('No plans yet')).toBeVisible();

    await page.getByRole('button', { name: 'New plan' }).click();
    const dialog = page.getByRole('dialog', { name: 'New plan' });
    await dialog.getByLabel('Name').fill(planName);
    await dialog.getByLabel('Description').fill('Everything included');
    await dialog.getByLabel('Features').fill('Ten seats\nPriority support');
    await dialog.getByLabel('Free trial (days)').fill('7');
    await dialog.getByRole('button', { name: 'Create plan' }).click();
    const row = page.getByRole('row', { name: new RegExp(planName) });
    await expect(row).toBeVisible();
    await expect(row.getByText('Draft')).toBeVisible();

    // A price is typed in money and stored in minor units; a bad amount never reaches the server.
    await row.getByRole('button', { name: `Edit ${planName}` }).click();
    const editor = page.getByRole('dialog', { name: `Edit ${planName}` });
    await editor.getByLabel('Amount (USD)').fill('19.999');
    await editor.getByRole('button', { name: 'Add price' }).click();
    await expect(editor.getByText(/valid amount/i)).toBeVisible();
    await editor.getByLabel('Amount (USD)').fill('19');
    await editor.getByRole('button', { name: 'Add price' }).click();
    await expect(editor.getByText('$19.00 / month')).toBeVisible();
    await page.screenshot({ path: 'test-results/provider-plan-editor.png', fullPage: true });
    await page.keyboard.press('Escape');
    await expect(row.getByText('$19.00 / month')).toBeVisible();

    // Publishing needs a connected Stripe account.
    await row.getByRole('button', { name: `Publish ${planName}` }).click();
    await expect(page.getByRole('alert')).toContainText('Finish connecting Stripe');
    await expect(row.getByText('Draft')).toBeVisible();

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();

    // --- Admin ---
    await page.getByLabel('Email').fill(adminEmail);
    await page.getByLabel('Password').fill(adminPassword);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/admin$/);
    const nav = page.getByRole('navigation', { name: 'Admin' });

    await nav.getByRole('link', { name: 'Plans' }).click();
    await page.getByRole('searchbox', { name: 'Search plans' }).fill(planName);
    const adminRow = page.getByRole('row', { name: new RegExp(planName) });
    await expect(adminRow).toContainText(`Plans Co ${stamp}`);
    await adminRow.getByRole('button', { name: `Archive ${planName}` }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Archive' }).click();
    await expect(adminRow.getByText('Archived')).toBeVisible();

    await nav.getByRole('link', { name: 'Webhooks' }).click();
    await expect(page.getByRole('heading', { name: 'Webhooks', level: 1 })).toBeVisible();
    await expect(page.getByText('No events yet')).toBeVisible();

    await nav.getByRole('link', { name: 'Settings' }).click();
    const stripe = page.getByRole('region', { name: 'Stripe connection' });
    await expect(stripe).toContainText('Not configured');
    await expect(stripe).toContainText('/api/v1/webhooks/stripe/connect');
    await expect(page.getByLabel('Minimum price (minor units)')).toHaveValue('100');
    await page.screenshot({ path: 'test-results/admin-stripe-settings.png', fullPage: true });

    await nav.getByRole('link', { name: 'Audit log' }).click();
    await expect(page.getByText('plan.force_archived').first()).toBeVisible();

    await page.getByRole('button', { name: 'Sign out' }).click();
    expect(problems).toEqual([]);
});
