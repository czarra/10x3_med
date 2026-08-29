# E2E Testing Rules (DiaGuide / Playwright)

Read this before generating or editing any spec under `tests/e2e/`. These rules
plus `seed.spec.ts` are the two quality levers — generated tests inherit whatever
they show.

## The rules

- Use `getByRole`, `getByLabel`, `getByText` as primary locators. Fall back to
  `getByTestId` only when accessibility attributes are ambiguous.
- Never use CSS selectors, XPath, or DOM structure to locate elements.
- Each test must be independently runnable — no shared state between tests. One
  self-contained cycle per test: setup, action, assertion, cleanup.
- Never use `page.waitForTimeout()`. Wait for a condition: `toBeVisible()`,
  `waitForURL()`, `waitForResponse()`.
- Assert the business outcome, not implementation details.
- Use unique identifiers (timestamp / `Date.now()` suffix) for test data so
  parallel runs and re-runs don't collide. Clean up what the test created
  (`afterEach`, or delete-before-assert).
- Authenticate via `storageState` (`auth.setup.ts`) — never log in through the UI
  inside an individual spec. The one exception is a spec whose subject *is* the
  login/redirect behaviour.
- Name the test after the risk / observable outcome, never `test('test 1', ...)`.
- Every assertion must fail if its `test-plan.md` risk materializes. Control
  question: would this assertion still pass if the risk came true? If yes, it's
  decorative.

## Project specifics

- **App under test:** the `php-e2e` container, `APP_ENV=e2e`, base URL
  `http://php-e2e` (override with `PLAYWRIGHT_BASE_URL`). It shares the
  `database-test` Postgres with PHPUnit.
- **Fixture data:** owned only by users whose email ends `@e2e.test`. Reset
  before the whole run by `POST /__e2e__/reset` (`global-setup.ts`). Never
  `TRUNCATE`; scope every cleanup to `@e2e.test` rows so PHPUnit is untouched.
  Do not run the PHPUnit and E2E suites concurrently.
- **Canonical users:** see `tests/e2e/fixtures.ts` /
  `tests/Support/E2e/E2eFixtures.php` (single source of truth).
- **Real vs mocked:** everything is real (auth, routing, Doctrine/Postgres,
  session, CSRF). DiaGuide calls no external APIs, so there is nothing to mock at
  the network layer. Keep it that way — a test that mocks the DB or auth proves
  nothing about the integration.
- **Locale is Polish** — button/label/heading names in locators are Polish
  strings (`Zaloguj się`, `Zapisz wpis`, `Usuń`, …).
- **Run:** `docker compose exec playwright npx playwright test`
  (single spec: `… npx playwright test tests/e2e/<file>.spec.ts`;
  first run after checkout: `docker compose exec playwright npm ci`).

## Fixture users & scenario data

Canonical `@e2e.test` users, (re)created by `POST /__e2e__/reset`
(`global-setup.ts`). `tests/Support/E2e/E2eFixtures.php` is the source of truth;
`tests/e2e/fixtures.ts` mirrors the credentials.

| User | Purpose | storageState |
|---|---|---|
| `patient-with-profile@e2e.test` | onboarded patient, no diary rows | `auth.setup.ts` → `var/e2e/.auth/patient.json` (chromium default) |
| `patient-fresh@e2e.test` | registered, no profile — lands on `/onboarding` | none |
| `patient-dashboard@e2e.test` | 3 seeded fasting mornings → a base-dose suggestion on `/pulpit` | `auth-dashboard.setup.ts` → `var/e2e/.auth/patient-dashboard.json` |

### Two data patterns

- **Spec creates its own rows** (default — see `seed.spec.ts`): unique id
  (`Date.now()` suffix), reuse `patient-with-profile`, delete-before-assert or in
  `afterEach`. Parallel-safe because ids never collide.
- **Spec mutates seeded state** (e.g. accepting a suggestion writes the profile —
  see `dashboard-base-dose-accept.spec.ts`): give it a *dedicated* user and
  re-seed that user's rows in `beforeEach` via a scoped `when@e2e` endpoint
  (`E2eFixtures::seedDashboardBaseDoseScenario()` +
  `POST /__e2e__/reset-dashboard-scenario`). The reset must be scoped by
  `user_id` and must **not** drop the `users` row, or the saved session breaks.
  Never re-seed a *shared* user in `beforeEach` — it races the other specs.

### Adding a scenario endpoint

1. Add a `public` method to `E2eFixtures` that wipes + reseeds one user's rows
   (raw `DELETE … WHERE user_id = :id`, then `entityManager->clear()`).
2. Expose it as `#[Route('/__e2e__/…', methods: ['POST'])]` in
   `E2eFixturesController` (the whole controller dir is `#[When('e2e')]`).
3. **Rebuild the e2e cache** — the e2e env runs with `APP_DEBUG=0`, so a new
   route or service definition is not picked up until:
   `docker compose exec php-e2e php bin/console cache:clear`.
