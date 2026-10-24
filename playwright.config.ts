import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: 'tests/e2e',
    use: { baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000' },
    reporter: 'list',
    workers: 1, // one dev server and one database are shared by every spec
});
