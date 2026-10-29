import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';

const php = process.env.ADMIN_TEST_PHP || 'php';
const browserDb = path.resolve('storage/framework/testing/admin-browser.sqlite');
export default defineConfig({
    testDir: './tests/browser',
    fullyParallel: false,
    workers: 1,
    use: { baseURL: 'http://127.0.0.1:8086', trace: 'retain-on-failure' },
    projects: [
        {
            name: 'desktop',
            use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 960 } },
        },
        {
            name: 'tablet',
            use: { ...devices['Desktop Chrome'], viewport: { width: 820, height: 1100 } },
        },
        {
            name: 'mobile',
            use: { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 } },
        },
    ],
    webServer: {
        command: `"${php}" scripts/browser-fixture.php && "${php}" artisan serve --host=127.0.0.1 --port=8086 --no-reload`,
        url: 'http://127.0.0.1:8086/up',
        reuseExistingServer: false,
        timeout: 60000,
        env: {
            APP_ENV: 'local',
            APP_DEBUG: 'true',
            APP_URL: 'http://127.0.0.1:8086',
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: browserDb,
            DB_URL: '',
            CACHE_STORE: 'database',
            DB_CACHE_TABLE: 'admin_cache',
            DB_CACHE_LOCK_TABLE: 'admin_cache_locks',
            SESSION_DRIVER: 'database',
            SESSION_TABLE: 'admin_sessions',
            SESSION_COOKIE: 'gamexsports_admin_browser_session',
            SESSION_SECURE_COOKIE: 'false',
            ADMIN_SMS_DRIVER: 'capture',
            ADMIN_BROWSER_TEST: '1',
        },
    },
});
