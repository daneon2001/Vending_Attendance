import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.E2E_BASE_URL ?? process.env.APP_URL ?? 'http://127.0.0.1:8000';
const authFile = 'tests/e2e/.auth/user.json';

export default defineConfig({
    testDir: './tests/e2e',
    timeout: 90_000,
    fullyParallel: true,
    expect: {
        timeout: 10_000,
    },
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
    },
    projects: [
        {
            name: 'setup',
            testMatch: /auth\.setup\.ts/,
        },
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                storageState: authFile,
            },
            dependencies: ['setup'],
        },
    ],
    webServer: {
        command: process.env.E2E_WEB_SERVER_COMMAND ?? 'php artisan serve --host=127.0.0.1 --port=8000',
        url: baseURL,
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
    },
});
