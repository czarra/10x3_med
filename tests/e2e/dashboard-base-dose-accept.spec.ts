import { test, expect } from '@playwright/test';

/**
 * Provenance
 *   Risk:   Accepting an algorithmic dosing suggestion in the browser must actually
 *           persist to the patient's profile, AND the dashboard must stop offering a
 *           change the patient already accepted. If the accept POST silently no-ops,
 *           writes the wrong value, or never records the adjustment (so the same
 *           suggestion keeps reappearing), the patient re-applies a real insulin
 *           change — or stops trusting the tool.
 *           (Adjacent to context/foundation/test-plan.md Risk #2, but Risk #2 itself —
 *           a wrong suggested number — is an algorithm/oracle concern covered by unit
 *           tests in tests/Service/Suggestion/*. This spec covers the accept LOOP.)
 *   Proves: /pulpit surfaces the base-dose suggestion (14 -> 15 j.) computed from three
 *           seeded fasting mornings; clicking "Zapisz nową dawkę bazową w profilu"
 *           persists 15 to the profile (read back on /profil) and the dashboard then
 *           reports no further base-dose suggestion.
 *   Layer:  browser E2E — BaseDoseSuggestionService (diary + profile + accepted-
 *           adjustment cutoff), the accept controller (CSRF -> profile write ->
 *           BaseDoseAdjustmentHistory write -> redirect) and the re-rendered dashboard
 *           only integrate at the HTTP boundary. A unit test proves the number; only a
 *           browser proves the button is wired to the right write and the page updates.
 *   Seed:   tests/e2e/seed.spec.ts
 *   Data:   patient-dashboard@e2e.test — dedicated fixture patient, re-seeded before
 *           each test via POST /__e2e__/reset-dashboard-scenario
 *           (E2eFixtures::seedDashboardBaseDoseScenario). Own storageState from
 *           auth-dashboard.setup.ts. Scoped to this one patient, so the beforeEach
 *           wipe never races the other e2e specs.
 */

test.use({ storageState: 'var/e2e/.auth/patient-dashboard.json' });

test.beforeEach(async ({ request }) => {
    const res = await request.post('/__e2e__/reset-dashboard-scenario');
    expect(res.ok(), await res.text()).toBeTruthy();
});

test('accepting the base-dose suggestion persists it to the profile and clears the suggestion', async ({
    page,
}) => {
    const baseDoseCard = page
        .getByRole('article')
        .filter({ has: page.getByRole('heading', { name: 'Dawka bazowa' }) });

    // --- Precondition: the seeded fasting run produces a 14 -> 15 j. suggestion ---
    await page.goto('/pulpit');
    await expect(
        baseDoseCard.getByText(/Obecna dawka bazowa:\s*14 j\.\s*→\s*Sugerowana:\s*15 j\./),
    ).toBeVisible();
    const acceptButton = baseDoseCard.getByRole('button', {
        name: 'Zapisz nową dawkę bazową w profilu',
    });
    await expect(acceptButton).toBeVisible();

    // --- Action: accept the suggestion ---
    await acceptButton.click();
    await expect(page).toHaveURL(/\/pulpit$/);
    await expect(page.getByText('Dawka bazowa została zaktualizowana.')).toBeVisible();

    // --- Assertion 1: the new base dose is persisted to the profile (real UI read) ---
    await page.goto('/profil');
    await expect(page.getByLabel('Dawka bazowa (j.)')).toHaveValue('15');

    // --- Assertion 2: the dashboard no longer re-suggests the accepted change ---
    await page.goto('/pulpit');
    await expect(
        baseDoseCard.getByText(
            'Brak wystarczających danych do zasugerowania zmiany dawki bazowej lub obecna dawka dobrze dopasowana.',
        ),
    ).toBeVisible();
    await expect(baseDoseCard.getByText(/Sugerowana:/)).toHaveCount(0);
});
