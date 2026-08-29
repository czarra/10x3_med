import { test, expect } from '@playwright/test';

/**
 * SEED EXEMPLAR — the pattern every generated E2E spec is modeled on.
 *
 * Demonstrates the four levers (see tests/e2e/e2e-rules.md):
 *   1. Role-based locators   — getByRole / getByLabel, never CSS / XPath.
 *   2. Test independence     — one self-contained cycle: setup, action, assert, cleanup.
 *   3. Wait for state        — toBeVisible() / waitForURL(), never waitForTimeout().
 *   4. Risk-tied name        — the test title states the observable outcome.
 *
 * Auth comes from storageState (auth.setup.ts) — this spec never logs in.
 */

/** `Date`-derived, collision-proof value so parallel runs and re-runs don't clash. */
function uniqueGlycemia(): number {
    return 1000 + (Date.now() % 900); // 1000–1899 mg/dL, always inside the entity's 21–2000 range
}

/** Format a Date as the `YYYY-MM-DDTHH:mm` string an <input type="datetime-local"> expects. */
function toDatetimeLocal(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

test('a logged diary entry appears in the history list and can be removed', async ({ page }) => {
    const glycemia = uniqueGlycemia();
    const measuredAt = new Date(Date.now() - 2 * 60 * 60 * 1000); // 2h ago — must be <= now
    measuredAt.setSeconds(0, 0);

    // --- Action: add the entry ---------------------------------------------
    await page.goto('/dziennik/nowy');
    await page.getByLabel('Poziom glikemii (mg/dL)').fill(String(glycemia));
    await page.getByLabel('Data i godzina pomiaru').fill(toDatetimeLocal(measuredAt));
    await page.getByRole('button', { name: 'Zapisz wpis' }).click();

    await expect(page.getByText('Wpis został zapisany.')).toBeVisible();

    // --- Assertion: it shows up in the history --------------------------------
    await page.goto('/dziennik/historia');
    const row = page
        .getByRole('row')
        .filter({ has: page.getByRole('cell', { name: String(glycemia), exact: true }) });
    await expect(row).toBeVisible();

    // --- Cleanup: delete the entry we created (inside the 24h window) --------
    page.once('dialog', (dialog) => dialog.accept()); // native confirm() on the delete form
    await row.getByRole('button', { name: 'Usuń' }).click();
    await expect(
        page.getByRole('cell', { name: String(glycemia), exact: true }),
    ).toHaveCount(0);
});
