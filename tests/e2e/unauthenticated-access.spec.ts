import { test, expect } from '@playwright/test';
import {
    PATIENT_ONLY_PAGE_ROUTES,
    PATIENT_ONLY_PAGE_ROUTES_FOR_AUTHED,
    PATIENT_ONLY_DOWNLOAD_ROUTES,
    PATIENT_ONLY_POST_ROUTES,
} from './fixtures';

/**
 * Provenance
 *   Risk:   context/foundation/test-plan.md — Risk #5
 *           "An unauthenticated request or session-boundary gap reaches a patient-only screen."
 *   Proves: every patient-only route bounces an anonymous request to /login, and
 *           a real logged-in patient is NOT bounced (the gate is specific, not blanket).
 *   Layer:  browser E2E — the firewall + access_control + form-login entry point
 *           + real session cookie only integrate at the HTTP boundary.
 *   Seed:   tests/e2e/seed.spec.ts
 *
 * Deliberately NOT re-covered here (already in SecurityControllerTest /
 * RegistrationControllerTest): wrong-password and duplicate-email negative cases.
 * test-plan.md flags "duplicating happy-path coverage that already exists" as the
 * anti-pattern for this risk.
 */

test.describe('Risk #5 — an unauthenticated request never reaches a patient-only screen', () => {
    test.describe('anonymous visitor (no session)', () => {
        test.use({ storageState: { cookies: [], origins: [] } });

        for (const route of PATIENT_ONLY_PAGE_ROUTES) {
            test(`GET ${route} redirects to the login page`, async ({ page }) => {
                await page.goto(route);

                await expect(page).toHaveURL(/\/login(\?|$)/);
                await expect(
                    page.getByRole('heading', { name: 'Zaloguj się' }),
                ).toBeVisible();
            });
        }

        for (const route of PATIENT_ONLY_DOWNLOAD_ROUTES) {
            test(`GET ${route} (file download) is refused and redirected to login`, async ({ request }) => {
                const res = await request.get(route, { maxRedirects: 0 });

                expect(res.status()).toBe(302);
                expect(res.headers()['location']).toContain('/login');
            });
        }

        for (const route of PATIENT_ONLY_POST_ROUTES) {
            test(`POST ${route} is refused for an anonymous request`, async ({ request }) => {
                const res = await request.post(route, { maxRedirects: 0 });

                expect(res.status()).toBe(302);
                expect(res.headers()['location']).toContain('/login');
            });
        }
    });

    test('a logged-in patient is NOT redirected away from the same screens (positive control)', async ({
        page,
    }) => {
        // Uses the storageState from auth.setup.ts (chromium project default).
        for (const route of PATIENT_ONLY_PAGE_ROUTES_FOR_AUTHED) {
            const response = await page.goto(route);

            expect(response?.status(), `${route} should not be a 4xx/5xx`).toBeLessThan(400);
            await expect(page, `${route} should not bounce to /login`).not.toHaveURL(/\/login/);
        }
    });

    test('a logged-in patient can download the CSV export (positive control)', async ({ request }) => {
        const res = await request.get('/dziennik/eksport', { maxRedirects: 0 });

        expect(res.status()).toBe(200);
        expect(res.headers()['content-type']).toContain('text/csv');
    });
});
