import inertia from '@inertiajs/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import vuetify from 'vite-plugin-vuetify';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.ts'], refresh: true }),
        inertia(),
        vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } }),
        vuetify({ autoImport: true }),
    ]),
    server: {
        host: '127.0.0.1',
        port: 5175,
        strictPort: true,
        allowedHosts: ['vite', 'localhost'],
        hmr: { host: 'localhost', port: 5175 },
        watch: { ignored: ['**/vendor/**', '**/storage/**'] },
    },
    lint: {
        ignorePatterns: ['vendor/**', 'node_modules/**', 'public/**'],
        options: { denyWarnings: true, typeAware: true },
    },
    fmt: { printWidth: 100, tabWidth: 4, singleQuote: true, semi: true },
});
