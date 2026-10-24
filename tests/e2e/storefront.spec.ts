import { expect, test, type Page } from '@playwright/test';

/**
 * Real-browser sweep of Phase 4: a provider builds a draft, previews it on their own subdomain, publishes it, edits
 * without publishing, and an admin moderates it. Serve with `npm run build && npm run e2e:serve` and seed an admin
 * (see admin.spec.ts). Chromium maps *.platform.test to localhost (playwright.config.ts).
 */
const adminEmail = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const adminPassword = process.env.E2E_ADMIN_PASSWORD ?? 'Password123!';
const port = new URL(process.env.E2E_BASE_URL ?? 'http://localhost:8000').port || '8000';

function watch(page: Page, problems: string[]) {
    page.on('console', (m) => m.type() === 'error' && !/status of (401|404)/.test(m.text()) && problems.push(`console: ${m.text()}`));
    page.on('requestfailed', (r) => problems.push(`failed: ${r.url()}`));
    page.on('response', (r) => r.status() >= 400 && ![401, 404].includes(r.status()) && problems.push(`${r.status()} ${r.url()}`));
}

test('provider builds, previews, publishes and edits a storefront; admin moderates it', async ({ page, browser, request }) => {
    const problems: string[] = [];
    watch(page, problems);

    const stamp = Date.now();
    const slug = `sf-${stamp}`;
    const email = `e2e-sf-${stamp}@example.com`;
    const site = `http://${slug}.platform.test:${port}/`;
    const headline = `Welcome to ${slug}`;

    const registered = await request.post('/api/v1/auth/register', {
        data: { name: 'Sia Store', email, password: 'Password123!', password_confirmation: 'Password123!' },
    });
    const token = (await registered.json()).data.access_token as string;
    const created = await request.post('/api/v1/provider/register', {
        headers: { Authorization: `Bearer ${token}` },
        data: { name: `Store ${stamp}`, slug, business_category: 'fitness', country: 'US', support_email: 'help@store.test' },
    });
    expect(created.status()).toBe(201);

    // A visitor sees "coming soon" while nothing is published.
    const visitor = await browser.newPage();
    watch(visitor, problems);
    await visitor.goto(site);
    await expect(visitor.getByRole('heading', { name: /coming soon/i })).toBeVisible();

    // Provider: build the draft.
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('Password123!');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.getByRole('navigation', { name: 'Provider' }).getByRole('link', { name: 'Storefront' }).click();
    await expect(page.getByText(/no sections yet/i)).toBeVisible();

    const form = page.getByRole('region', { name: 'Section editor' });
    const back = () => page.getByRole('button', { name: 'Back to sections' }).click();
    const addSection = async (label: RegExp) => {
        await page.getByRole('button', { name: 'Add section' }).click();
        await page.getByRole('dialog').getByRole('button', { name: label }).click();
    };

    // Add a hero from the picker; its form opens on its own.
    await addSection(/^Hero/);
    await form.getByLabel('Headline', { exact: true }).fill(headline);
    await form.getByLabel('Button label').fill('Join now');
    await form.getByLabel('Button link').fill('https://store.test/join');

    // Media library: upload an image into the hero, then reuse it from the library as the share image.
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
    await form.getByLabel('Image file').setInputFiles({ name: 'hero.png', mimeType: 'image/png', buffer: png });
    await expect(form.getByRole('img', { name: 'Image preview' })).toBeVisible();
    await expect(form.getByLabel('Image', { exact: true })).toHaveValue(/\/storage\/providers\/.+\.png$/);
    await back();
    await addSection(/^Plans/);
    await back();
    await addSection(/^FAQ/);
    await back();
    await expect(page.getByRole('list', { name: 'Sections' }).getByRole('listitem')).toHaveCount(3);

    await page.getByRole('button', { name: 'Edit Search and sharing' }).click();
    await form.getByRole('button', { name: 'Choose from library' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'hero.png' }).click();
    await expect(form.getByLabel('Share image', { exact: true })).toHaveValue(/\/storage\/providers\/.+\.png$/);
    await form.getByLabel('Page title').fill(`Store ${stamp} | Fitness`);
    await back();

    const livePreview = page.getByRole('region', { name: 'Live preview' });
    await expect(livePreview.getByRole('heading', { name: headline, level: 1 })).toBeVisible();
    await expect(page.getByText('Unsaved changes')).toBeVisible();
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(page.getByText('Draft saved')).toBeVisible();
    await page.screenshot({ path: 'test-results/storefront-editor.png', fullPage: true });

    // Full-width preview with a phone-sized frame, then back to the editor.
    await page.getByRole('group', { name: 'View' }).getByRole('button', { name: 'Preview' }).click();
    await page.getByRole('button', { name: 'Mobile' }).click();
    await expect(page.getByTestId('preview-frame')).toHaveAttribute('data-device', 'mobile');
    await expect(livePreview.getByRole('heading', { name: headline, level: 1 })).toBeVisible();
    await page.waitForTimeout(300); // let the toggle transition finish
    await page.screenshot({ path: 'test-results/storefront-preview-mobile.png' });
    await page.getByRole('group', { name: 'View' }).getByRole('button', { name: 'Editor' }).click();
    await expect(page.getByRole('list', { name: 'Sections' })).toBeVisible();

    // Preview opens the draft on the provider's own subdomain; the public still sees "coming soon".
    const [preview] = await Promise.all([page.waitForEvent('popup'), page.getByRole('button', { name: 'Preview in new tab' }).click()]);
    watch(preview, problems);
    await expect(preview).toHaveURL(new RegExp(`${slug}\\.platform\\.test`));
    await expect(preview.getByRole('heading', { name: headline, level: 1 })).toBeVisible();
    await expect(preview.getByRole('status')).toContainText(/preview/i);
    await expect(preview).toHaveTitle(`Store ${stamp} | Fitness`);
    await preview.close();
    await visitor.reload();
    await expect(visitor.getByRole('heading', { name: /coming soon/i })).toBeVisible();

    // Publish: the public page goes live.
    await page.getByRole('button', { name: 'Publish', exact: true }).click();
    await expect(page.getByText(/^Published /)).toBeVisible();
    await visitor.reload();
    await expect(visitor.getByRole('heading', { name: headline, level: 1 })).toBeVisible();
    await expect(visitor.getByRole('status')).toHaveCount(0);
    await expect(visitor.getByRole('link', { name: 'Join now' })).toHaveAttribute('href', 'https://store.test/join');
    await visitor.screenshot({ path: 'test-results/storefront-public.png', fullPage: true });

    // Editing the draft changes nothing publicly until it is published again.
    await page.getByRole('button', { name: 'Edit Hero' }).click();
    await form.getByLabel('Headline', { exact: true }).fill(`${headline} v2`);
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(page.getByText('Draft saved')).toBeVisible();
    await expect(page.getByText(/changes are not published/i)).toBeVisible();
    await visitor.reload();
    await expect(visitor.getByRole('heading', { name: headline, level: 1, exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Publish', exact: true }).click();
    await visitor.reload();
    await expect(visitor.getByRole('heading', { name: `${headline} v2`, level: 1 })).toBeVisible();

    // Admin moderates: unpublish, then deactivate.
    const adminContext = await browser.newContext();
    const admin = await adminContext.newPage();
    watch(admin, problems);
    await admin.goto('/login');
    await admin.getByLabel('Email').fill(adminEmail);
    await admin.getByLabel('Password').fill(adminPassword);
    await admin.getByRole('button', { name: 'Sign in' }).click();
    await admin.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Providers' }).click();
    await admin.getByRole('searchbox', { name: 'Search providers' }).fill(slug);
    await admin
        .getByRole('row', { name: new RegExp(slug) })
        .getByRole('button', { name: /View/ })
        .click();
    await admin.getByRole('dialog').getByRole('button', { name: 'Unpublish storefront' }).click();
    await admin.getByRole('alertdialog').getByRole('button', { name: 'Unpublish', exact: true }).click();
    await expect(admin.getByRole('alertdialog')).toBeHidden();
    await visitor.reload();
    await expect(visitor.getByRole('heading', { name: /coming soon/i })).toBeVisible();

    await admin.getByRole('dialog').getByRole('button', { name: 'Deactivate' }).click();
    await admin.getByRole('alertdialog').getByRole('button', { name: 'Deactivate' }).click();
    await expect(admin.getByRole('row', { name: new RegExp(slug) }).getByText('Inactive')).toBeVisible();
    await visitor.reload();
    await expect(visitor.getByRole('heading', { name: /unavailable/i })).toBeVisible();
    await visitor.screenshot({ path: 'test-results/storefront-unavailable.png' });

    // A made-up address is a clean not-found page, not an error.
    await visitor.goto(`http://nobody-${stamp}.platform.test:${port}/`);
    await expect(visitor.getByRole('heading', { name: /couldn.t find/i })).toBeVisible();

    expect(problems).toEqual([]);
    await adminContext.close();
});
