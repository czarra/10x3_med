import { request, type FullConfig } from '@playwright/test';

/**
 * Resets E2E fixture data before the suite runs.
 *
 * Hits `POST /__e2e__/reset` on the running app (a controller that only exists
 * when APP_ENV=e2e). That endpoint deletes every `@e2e.test`-owned row and
 * recreates the canonical patients — so each run starts from a known state and
 * PHPUnit's own rows are never touched.
 *
 * Retries for ~30s: right after `docker compose up`, `php-e2e` may still be
 * warming its prod-mode cache (its healthcheck has the same grace window), and
 * `docker compose exec playwright ...` does not wait for that.
 */
const RESET_PATH = '/__e2e__/reset';
const MAX_ATTEMPTS = 12;
const RETRY_DELAY_MS = 2_500;

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

async function globalSetup(_config: FullConfig): Promise<void> {
    const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://php-e2e';
    const ctx = await request.newContext({ baseURL });

    try {
        let lastError = '';

        for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
            try {
                const res = await ctx.post(RESET_PATH);
                if (res.ok()) {
                    if (attempt > 1) {
                        console.log(`[global-setup] fixtures reset OK after ${attempt} attempts`);
                    }
                    return;
                }
                lastError = `${res.status()} ${res.statusText()}\n${await res.text()}`;
            } catch (err) {
                lastError = err instanceof Error ? err.message : String(err);
            }

            if (attempt < MAX_ATTEMPTS) {
                console.log(
                    `[global-setup] ${baseURL}${RESET_PATH} not ready (attempt ${attempt}/${MAX_ATTEMPTS}), retrying in ${RETRY_DELAY_MS}ms…`,
                );
                await sleep(RETRY_DELAY_MS);
            }
        }

        throw new Error(
            `E2E fixtures reset failed after ${MAX_ATTEMPTS} attempts. Is php-e2e up and healthy?\nLast error: ${lastError}`,
        );
    } finally {
        await ctx.dispose();
    }
}

export default globalSetup;
