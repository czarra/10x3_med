import { test as setup, expect } from '@playwright/test';
import { PATIENT_WITH_PROFILE } from './fixtures';

const storageState = 'var/e2e/.auth/patient.json';

/**
 * Logs the canonical patient in once through the real login form and saves the
 * session, so individual specs never authenticate through the UI (E2E rule:
 * "Use storageState for authentication"). Runs in the `setup` project, which the
 * `chromium` project depends on.
 */
setup('authenticate as a patient with a completed profile', async ({ page }) => {
    await page.goto('/login');

    await page.getByLabel('E-mail').fill(PATIENT_WITH_PROFILE.email);
    await page.getByLabel('Hasło').fill(PATIENT_WITH_PROFILE.password);
    await page.getByRole('button', { name: 'Zaloguj się' }).click();

    // form_login default_target_path is patient_profile (/profil)
    await page.waitForURL('**/profil');
    await expect(page.getByRole('button', { name: '☰' })).toBeVisible();

    await page.context().storageState({ path: storageState });
});
