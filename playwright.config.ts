import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: 'tests/e2e',
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000',
        // Provider storefronts live on <slug>.platform.test; resolve those names to this machine without touching /etc/hosts (T0.2).
        launchOptions: { args: ['--host-resolver-rules=MAP *.platform.test 127.0.0.1'] },
    },
    reporter: 'list',
    workers: 1, // one dev server and one database are shared by every spec
});
