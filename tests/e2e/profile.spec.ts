import { expect, test } from '@playwright/test';

// 1x1 transparent PNG
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');

test('FR-A8 edit name, upload and remove a photo, change password, sign in with it', async ({ page }) => {
    const problems: string[] = [];
    page.on('console', (m) => m.type() === 'error' && !m.text().includes('401') && problems.push(m.text()));
    page.on('requestfailed', (r) => problems.push(`failed: ${r.url()}`));
    page.on('response', (r) => r.status() >= 400 && r.status() !== 401 && r.status() !== 422 && problems.push(`${r.status()} ${r.url()}`));

    const email = `e2e-profile-${Date.now()}@example.com`;
    await page.goto('/register');
    await page.getByLabel('Full name').fill('Profile User');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('Password123!');
    await page.getByLabel('Confirm password').fill('Password123!');
    await page.getByRole('button', { name: 'Create account' }).click();
    await page.getByRole('link', { name: 'Edit profile' }).click();
    await expect(page.getByRole('heading', { name: 'Profile' })).toBeVisible();

    await page.getByLabel('Name').fill('Renamed User');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByRole('status')).toContainText('Profile saved');

    await page.getByLabel('Upload photo').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: PNG });
    const photo = page.getByRole('img', { name: 'Your photo' });
    await expect(photo).toBeVisible();
    await expect.poll(() => photo.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
    await page.screenshot({ path: 'test-results/profile.png', fullPage: true });

    await page.getByRole('button', { name: 'Remove photo' }).click();
    await expect(photo).toBeHidden();

    await page.getByLabel('Current password').fill('Password123!');
    await page.getByLabel('New password', { exact: true }).fill('NewPassword456!');
    await page.getByLabel('Confirm new password').fill('NewPassword456!');
    await page.getByRole('button', { name: 'Update password' }).click();
    await expect(page.getByText(/Password updated/)).toBeVisible();

    await page.reload(); // the session issued by the password change survives a reload
    await expect(page.getByRole('heading', { name: 'Profile' })).toBeVisible();

    await page.goto('/account');
    await page.getByRole('button', { name: 'Sign out' }).click();
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('NewPassword456!');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Hello, Renamed User' })).toBeVisible();

    expect(problems).toEqual([]);
});
