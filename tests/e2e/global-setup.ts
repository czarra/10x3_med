import { request, type FullConfig } from '@playwright/test';

/**
 * Resets E2E fixture data before the suite runs.
 *
 * Hits `POST /__e2e__/reset` on the running app (a controller that only exists
 * when APP_ENV=e2e). That endpoint deletes every `@e2e.test`-owned row and
 * recreates the canonical patients — so each run starts from a known state and
 * PHPUnit's own rows are never touched.
 */
async function globalSetup(_config: FullConfig): Promise<void> {
    const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://php-e2e';
    const ctx = await request.newContext({ baseURL });

    try {
        const res = await ctx.post('/__e2e__/reset');
        if (!res.ok()) {
            throw new Error(
                `E2E fixtures reset failed: ${res.status()} ${res.statusText()}\n${await res.text()}`,
            );
        }
    } finally {
        await ctx.dispose();
    }
}

export default globalSetup;
