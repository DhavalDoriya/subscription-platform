import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [laravel({ input: ['resources/css/app.css', 'resources/js/main.tsx'], refresh: true }), react(), tailwindcss()],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    server: {
        host: '0.0.0.0',
        hmr: { host: 'localhost' },
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
    test: {
        environment: 'jsdom',
        environmentOptions: { jsdom: { url: 'http://localhost' } },
        globals: true,
        // The first test of a file pays for importing the UI libraries; on a busy machine that can pass the 5s default.
        testTimeout: 15000,
        setupFiles: ['resources/js/test/setup.ts'],
        include: ['resources/js/**/*.test.{ts,tsx}'],
    },
});
