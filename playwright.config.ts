import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright config for the DiaGuide browser E2E suite.
 *
 * Runs entirely in Docker, the same way PHPUnit does:
 *   docker compose exec playwright npm ci          # once, after checkout
 *   docker compose exec playwright npx playwright test
 *
 * The `playwright` service targets the `php-e2e` app instance (APP_ENV=e2e),
 * which shares the `database-test` Postgres service with PHPUnit. Test data is
 * owned exclusively by rows whose email ends in `@e2e.test` and is reset by
 * global-setup before every run — so the two suites never clobber each other
 * as long as they don't run concurrently (see AGENTS.md).
 */

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://php-e2e';
const storageState = 'var/e2e/.auth/patient.json';

export default defineConfig({
    testDir: './tests/e2e',
    outputDir: './var/e2e/test-results',

    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    workers: process.env.CI ? 1 : undefined,

    reporter: [
        ['list'],
        ['html', { outputFolder: 'var/e2e/report', open: 'never' }],
    ],

    timeout: 30_000,
    expect: { timeout: 5_000 },

    globalSetup: './tests/e2e/global-setup.ts',

    use: {
        baseURL,
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },

    projects: [
        // Registers a session for the canonical patient and saves storageState.
        { name: 'setup', testMatch: /.*\.setup\.ts/ },

        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'], storageState },
            dependencies: ['setup'],
        },
    ],
});
