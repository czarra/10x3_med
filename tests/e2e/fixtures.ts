/**
 * Shared constants for the DiaGuide E2E suite.
 *
 * The two canonical users below are (re)created by `POST /__e2e__/reset`
 * (global-setup.ts) — see tests/Support/E2e/E2eFixtures.php, which is the single
 * source of truth for their credentials. Keep both sides in sync.
 */

export const PATIENT_WITH_PROFILE = {
    email: 'patient-with-profile@e2e.test',
    password: 'E2ePassw0rd!',
} as const;

export const PATIENT_WITHOUT_PROFILE = {
    email: 'patient-fresh@e2e.test',
    password: 'E2ePassw0rd!',
} as const;

/**
 * Dedicated patient for the dashboard recommendation-accept spec. Isolated so that
 * spec can wipe and re-seed its diary/adjustment rows before every test
 * (`POST /__e2e__/reset-dashboard-scenario`) without racing the specs that drive
 * the two patients above. Own storageState from `auth-dashboard.setup.ts`.
 */
export const PATIENT_DASHBOARD = {
    email: 'patient-dashboard@e2e.test',
    password: 'E2ePassw0rd!',
} as const;

/**
 * Patient-only screens that render HTML — safe to drive with `page.goto()`.
 * `/dziennik/1/edytuj` is id-addressed; for an anonymous request the id is
 * irrelevant because the firewall redirects before the controller/voter runs.
 */
export const PATIENT_ONLY_PAGE_ROUTES = [
    '/onboarding',
    '/profil',
    '/pulpit',
    '/dziennik/nowy',
    '/dziennik/historia',
    '/dziennik/1/edytuj',
] as const;

/** Patient-only page routes that a logged-in patient with a profile can open without a 4xx. */
export const PATIENT_ONLY_PAGE_ROUTES_FOR_AUTHED = [
    '/onboarding', // redirects an onboarded patient to /profil — still not /login
    '/profil',
    '/pulpit',
    '/dziennik/nowy',
    '/dziennik/historia',
] as const;

/** Patient-only route that streams a file download (breaks `page.goto`) — check via APIRequestContext. */
export const PATIENT_ONLY_DOWNLOAD_ROUTES = ['/dziennik/eksport'] as const;

/** Patient-only routes that only accept POST. */
export const PATIENT_ONLY_POST_ROUTES = [
    '/pulpit/przelicznik/akceptuj',
    '/pulpit/dawka-bazowa/akceptuj',
    '/dziennik/1/usun',
] as const;
