import { test as setup, expect } from '@playwright/test';
import { PATIENT_DASHBOARD } from './fixtures';

const storageState = 'var/e2e/.auth/patient-dashboard.json';

/**
 * Logs the dedicated dashboard-scenario patient in once through the real login
 * form and saves the session, so `dashboard-base-dose-accept.spec.ts` never
 * authenticates through the UI (E2E rule: "Use storageState for authentication").
 * Runs in the `setup` project (testMatch /.*\.setup\.ts/), which `chromium`
 * depends on — no playwright.config.ts change needed.
 */
setup('authenticate as the dashboard-scenario patient', async ({ page }) => {
    await page.goto('/login');

    await page.getByLabel('E-mail').fill(PATIENT_DASHBOARD.email);
    await page.getByLabel('Hasło').fill(PATIENT_DASHBOARD.password);
    await page.getByRole('button', { name: 'Zaloguj się' }).click();

    // form_login default_target_path is patient_profile (/profil)
    await page.waitForURL('**/profil');
    await expect(page.getByRole('button', { name: '☰' })).toBeVisible();

    await page.context().storageState({ path: storageState });
});
