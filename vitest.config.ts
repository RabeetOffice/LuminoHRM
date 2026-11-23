import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

// Standalone config so unit tests do not load the Laravel/Vite build plugins.
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
        environment: 'node',
    },
});
