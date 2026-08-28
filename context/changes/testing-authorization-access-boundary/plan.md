# Authorization & Access-Boundary Hardening Implementation Plan

## Overview

Close the two gaps identified in `context/changes/testing-authorization-access-boundary/research.md`: `DiaryController::history` has no cross-account (risk #1) test, and 9 of the 10 patient-only route actions have no (or only partial) unauthenticated-access (risk #5) test. Also fix and regression-test a related finding surfaced during research: registration's duplicate-email error discloses account existence.

## Current State Analysis

- **Risk #1**: `DiaryController` uses two independent ownership mechanisms — `edit`/`delete` go through `DiaryEntryVoter` (`src/Security/DiaryEntryVoter.php:16-42`) after an unscoped `find($id)`, denying with 404; `new`/`history`/`export` never call the Voter and rely entirely on `WHERE e.user = :user` query-scoping (`src/Repository/DiaryEntryRepository.php:41-49`). Cross-account functional tests already exist for `edit` (`tests/Controller/DiaryControllerTest.php:338-358`), `delete` (`:435-461`), and `export` (`:597-621`). `history()` (`src/Controller/DiaryController.php:117-132`) has none.
- **Risk #5**: `config/packages/security.yaml:29` protects 10 controller actions via one `access_control` rule + per-action `#[IsGranted('ROLE_USER')]`. Only `diary_entry_export` has a genuine fresh-anonymous-client test (`tests/Controller/DiaryControllerTest.php:639-645`); `patient_profile` has only a post-logout variant (`tests/Controller/SecurityControllerTest.php:67-91`); the other 8 actions (`patient_onboarding`, `patient_dashboard`, `patient_dashboard_accept_ratio`, `patient_dashboard_accept_base_dose`, `diary_entry_new`, `diary_entry_edit`, `diary_entry_delete`, `diary_entry_history`) have none. The anonymous `/` → `/profil` → `/login` chain (`HomeController` always redirects to `patient_profile`) is also untested for the anonymous case.
- **Related finding (out of the original two risks)**: `src/Entity/User.php:15`'s `#[UniqueEntity(...)]` message (`'Istnieje już konto z tym adresem e-mail.'`) discloses that a submitted email is already registered — confirmed to be the sole disclosure surface (no translation files exist, the message is inline in the PHP attribute).
- **Fixture duplication**: `createUser`/`createProfile`/`createEntry`/`cleanupUser`-style private helpers are independently duplicated (with drifted names/signatures) across `tests/Controller/DiaryControllerTest.php`, `tests/Service/Export/DiaryExportServiceTest.php`, `tests/Controller/OnboardingControllerTest.php`, `tests/Controller/DashboardControllerTest.php`, `tests/Controller/ProfileControllerTest.php`, and `tests/Controller/HomeControllerTest.php`. No shared `tests/Support/`-style utility directory exists yet.

## Desired End State

- `DiaryController::history` has a cross-account regression test with the same mixed-dataset rigor as the existing `export` test.
- All 10 patient-only route actions, plus the anonymous `/` → `/profil` → `/login` chain, have a passing "requires authentication" regression test.
- Registration's duplicate-email path returns a generic error that does not confirm account existence, with a regression test locking that behavior in.
- A shared `DiaryFixturesTrait` exists as the canonical two-user fixture pattern for Diary-related tests (the pattern test-plan.md §6.3 left as "TBD"), adopted by the two Diary test files this phase touches.

Verification: `docker compose exec php vendor/bin/phpunit` passes in full, `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff` reports no changes, and `docker compose exec php vendor/bin/phpstan analyse` passes (only `src/Entity/User.php`'s change falls under its `src/`-only scope).

### Key Discoveries:

- The 404-over-403 anti-enumeration convention for diary-entry ownership denial (established in `context/archive/2026-08-27-edit-delete-diary-entry/`) has no analogue yet for the registration email-enumeration case — this phase establishes the first fix for that class of leak in this codebase.
- Anonymous POST requests to CSRF-protected actions (`DashboardController.php:59,102`) never reach the CSRF check — the firewall's `access_control` redirect happens first — so unauthenticated-access tests for those two routes need no `_csrf_token` param.
- `DiaryExportServiceTest` extends `KernelTestCase` (not `WebTestCase`) and only ever creates `User`/`DiaryEntry` rows (never a `PatientProfile`), with a `diary-export-` email prefix distinct from `DiaryControllerTest`'s `diary-` prefix — the shared trait's `createUser` needs a prefix parameter to preserve both.

## What We're NOT Doing

- Not unifying `DiaryController`'s two ownership mechanisms (e.g. adding a `DIARY_ENTRY_VIEW`/`EXPORT` Voter attribute) — an architecture change, not a test-coverage gap; flagged as an open question in `research.md` for a separate design decision.
- Not building a full mailer-driven anti-enumeration registration flow — no mailer is configured in this repo; only the generic-message fix is in scope.
- Not touching risks #2, #3, or #4 from `context/foundation/test-plan.md` §2 — those belong to later rollout phases.
- Not changing `access_control` regex or any `#[IsGranted]` attribute — current config was verified correct; only test coverage (and the one `User.php` message string) changes.
- Not migrating `OnboardingControllerTest`, `DashboardControllerTest`, `ProfileControllerTest`, or `HomeControllerTest` onto the shared trait — none of the new tests added to those files need fixture helpers (they're anonymous-client-only), so migrating their existing, unrelated private helpers is out of scope for this phase.

## Implementation Approach

Four phases, each independently shippable: (1) extract the shared fixture trait as a foundation, (2) close the risk #1 gap using it, (3) close the risk #5 gaps (no fixtures needed — pure anonymous-client assertions), (4) fix and test the email-enumeration finding. Phases 2-4 have no dependency on each other and could ship in any order after phase 1; the plan sequences them by risk priority.

## Phase 1: Shared Diary Test-Fixture Trait

### Overview

Extract the duplicated `createUser`/`createProfile`/`createEntry`/`cleanupUser` helpers from `DiaryControllerTest` and `DiaryExportServiceTest` into one reusable trait, so Phase 2's new test (and any future Diary test) has one canonical fixture pattern to use.

### Changes Required:

#### 1. New fixture trait

**File**: `tests/Support/DiaryFixturesTrait.php` (new file, new `tests/Support/` directory)

**Intent**: Consolidate the two existing, independently-drifted copies of the two-user/entry fixture pattern into one trait, fulfilling test-plan.md §6.3's open "TBD" for a documented two-fixture-user pattern.

**Contract**: `namespace App\Tests\Support;` `trait DiaryFixturesTrait` with protected methods:
- `entityManager(): EntityManagerInterface` — `static::getContainer()->get(EntityManagerInterface::class)`, same as both existing copies.
- `createUser(EntityManagerInterface $entityManager, string $emailPrefix = 'diary'): User` — same body as `DiaryControllerTest.php:759-768`, but the email format becomes `sprintf('%s-%s@example.test', $emailPrefix, uniqid())` so callers can preserve their existing prefix.
- `createProfile(EntityManagerInterface $entityManager, User $user, int $baseDose, float $insulinWwRatio): PatientProfile` — identical to `DiaryControllerTest.php:770-777`.
- `createEntry(EntityManagerInterface $entityManager, User $user, int $glycemiaMgDl, \DateTimeImmutable $measuredAt, ?\DateTimeImmutable $createdAt = null): DiaryEntry` — identical to `DiaryControllerTest.php:729-747`, carrying over the `backdateCreatedAt` reflection helper (`:749-752`) as a private trait method; the optional `$createdAt` param is a superset of `DiaryExportServiceTest`'s narrower version so existing call sites there are unaffected by its absence.
- `cleanupUser(EntityManagerInterface $entityManager, User $user): void` — identical to `DiaryControllerTest.php:779-787` (deletes from `ratio_adjustment_histories`, `base_dose_adjustment_histories`, `diary_entries`, `patient_profiles`, `users` in that order) — the 5-table superset of `DiaryExportServiceTest`'s narrower 2-table `cleanup()`; the extra `DELETE` statements are no-ops for suites that never wrote to those tables.

#### 2. Adopt trait in DiaryControllerTest

**File**: `tests/Controller/DiaryControllerTest.php`

**Intent**: Remove the now-duplicated private fixture methods in favor of the shared trait.

**Contract**: Add `use \App\Tests\Support\DiaryFixturesTrait;` to the class body; delete the private `entityManager` (`754-757`), `createUser` (`759-768`), `createProfile` (`770-777`), `createEntry`/`backdateCreatedAt` (`729-752`), and `cleanupUser` (`779-787`) method bodies. No call-site changes needed — the trait's default `emailPrefix` of `'diary'` matches this file's existing `'diary-'` prefix exactly.

#### 3. Adopt trait in DiaryExportServiceTest

**File**: `tests/Service/Export/DiaryExportServiceTest.php`

**Intent**: Adopt the same trait, reconciling this file's narrower/differently-named duplicates.

**Contract**: Add `use \App\Tests\Support\DiaryFixturesTrait;`; delete the private `createUser` (`188-197`) and `cleanup` (`214-219`) methods; update every `$this->createUser($entityManager)` call site to `$this->createUser($entityManager, 'diary-export')` to preserve the existing `diary-export-` email prefix; rename every `$this->cleanup($entityManager, $user)` call site to `$this->cleanupUser($entityManager, $user)`. Leave `boot()` (`181-186`) unchanged — it still calls `self::bootKernel()` before any fixture method runs, which `KernelTestCase` requires and the trait's `entityManager()` does not do on its own (matching today's behavior).

### Success Criteria:

#### Automated Verification:

- `docker compose exec php vendor/bin/phpunit tests/Controller/DiaryControllerTest.php` passes with no regressions
- `docker compose exec php vendor/bin/phpunit tests/Service/Export/DiaryExportServiceTest.php` passes with no regressions
- `docker compose exec php vendor/bin/phpunit` (full suite) passes
- `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff` reports no changes needed on the new trait file and both edited test files

#### Manual Verification:

- Diff review confirms no test assertion was weakened or removed during the refactor — only the fixture plumbing changed, not what each test asserts

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 2: Risk #1 — `history` Cross-Account Coverage

### Overview

Add the one missing cross-account test for `DiaryController::history`, closing risk #1's last uncovered action.

### Changes Required:

#### 1. New cross-account history test

**File**: `tests/Controller/DiaryControllerTest.php`

**Intent**: Prove `GET /dziennik/historia` never exposes another patient's entries, using the same mixed-dataset rigor as the existing `export` cross-account test rather than a weaker empty-state check — a broken/no-op query would still pass an empty-state-only test, but not this one.

**Contract**: New test method `testHistoryDoesNotExposeAnotherUsersEntries` using `\App\Tests\Support\DiaryFixturesTrait` (adopted in Phase 1): create user A with `createProfile` + one `createEntry` at a distinct glycemia value (e.g. `111`), and user B with `createProfile` + one `createEntry` at a different distinct value (e.g. `222`); `$client->loginUser($userB)` (matching the existing convention, e.g. `:338`); `GET /dziennik/historia`; assert the response body contains `'222'` and does not contain `'111'`; clean up both users via `cleanupUser` in a `finally` block, mirroring `testEditReturns404ForAnotherUsersEntry`'s structure (`:338-358`).

### Success Criteria:

#### Automated Verification:

- `docker compose exec php vendor/bin/phpunit tests/Controller/DiaryControllerTest.php` passes including the new test
- `docker compose exec php vendor/bin/phpunit` (full suite) passes
- `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff` reports no changes needed

#### Manual Verification:

- Temporarily remove or break the `WHERE e.user = :user` scoping in `DiaryEntryRepository::findByUserOrderedByMeasuredAtDesc` (`:41-49`), confirm the new test fails, then revert — proves the test has real signal rather than trivially passing

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 3: Risk #5 — Unauthenticated-Access Coverage

### Overview

Add "requires authentication" tests for the 7 previously-uncovered patient-only route actions, the fresh-anonymous-client variant for `patient_profile`, and the anonymous `/` → `/profil` → `/login` redirect chain — closing risk #5's coverage gap in full, matching the existing `testExportRequiresAuthentication` (`tests/Controller/DiaryControllerTest.php:639-645`) shape throughout. None of these tests need fixture helpers.

### Changes Required:

#### 1. Onboarding

**File**: `tests/Controller/OnboardingControllerTest.php`

**Intent**: Close the coverage gap for `patient_onboarding` (`src/Controller/OnboardingController.php:19`).

**Contract**: New test `testOnboardingRequiresAuthentication` — fresh `static::createClient()`, `GET /onboarding`, `assertResponseRedirects('/login')`.

#### 2. Dashboard (3 actions)

**File**: `tests/Controller/DashboardControllerTest.php`

**Intent**: Close the coverage gap for all three `patient_dashboard*` actions (`src/Controller/DashboardController.php:21,42,85`).

**Contract**: Three new tests, each a fresh `static::createClient()` + `assertResponseRedirects('/login')`:
- `testDashboardRequiresAuthentication` — `GET /pulpit`
- `testAcceptRatioRequiresAuthentication` — `POST /pulpit/przelicznik/akceptuj`, no `_csrf_token` param needed (firewall redirect precedes the CSRF check at `DashboardController.php:59`)
- `testAcceptBaseDoseRequiresAuthentication` — `POST /pulpit/dawka-bazowa/akceptuj`, same reasoning (`DashboardController.php:102`)

#### 3. Profile (fresh-anonymous variant)

**File**: `tests/Controller/ProfileControllerTest.php`

**Intent**: Close the partial gap — today's only `/profil` denial coverage is the post-logout case in `SecurityControllerTest::testLogoutThenProfileRedirectsToLogin` (`:67-91`), not a from-scratch anonymous client.

**Contract**: New test `testProfileRequiresAuthenticationForFreshAnonymousClient` — fresh `static::createClient()`, `GET /profil`, `assertResponseRedirects('/login')`.

#### 4. Diary (4 remaining actions)

**File**: `tests/Controller/DiaryControllerTest.php`

**Intent**: Close the remaining `DiaryController` coverage gaps — only `export` had this test before this phase.

**Contract**: Four new tests, each fresh `static::createClient()` + `assertResponseRedirects('/login')`:
- `testNewRequiresAuthentication` — `GET /dziennik/nowy`
- `testEditRequiresAuthentication` — `GET /dziennik/1/edytuj` (a literal placeholder id is sufficient — the `access_control` redirect fires before the route ever reaches `DiaryEntryRepository::find($id)`, so no real entry needs to exist)
- `testDeleteRequiresAuthentication` — `POST /dziennik/1/usun`, same reasoning
- `testHistoryRequiresAuthentication` — `GET /dziennik/historia`

#### 5. Home redirect chain

**File**: `tests/Controller/HomeControllerTest.php`

**Intent**: Close the previously-undocumented gap — `HomeController` always redirects to `patient_profile` regardless of auth state, and the only existing test (`testHomeRedirectsAuthenticatedUserToProfile`, `:12`) covers just the authenticated case.

**Contract**: New test `testAnonymousHomeRedirectsThroughProfileToLogin` — fresh `static::createClient()`; `GET /`; `assertResponseRedirects('/profil')` (first hop, unconditional); `$client->followRedirect()`; `assertResponseRedirects('/login')` (second hop, from `access_control`).

### Success Criteria:

#### Automated Verification:

- `docker compose exec php vendor/bin/phpunit tests/Controller/OnboardingControllerTest.php` passes including the new test
- `docker compose exec php vendor/bin/phpunit tests/Controller/DashboardControllerTest.php` passes including the 3 new tests
- `docker compose exec php vendor/bin/phpunit tests/Controller/ProfileControllerTest.php` passes including the new test
- `docker compose exec php vendor/bin/phpunit tests/Controller/DiaryControllerTest.php` passes including the 4 new tests
- `docker compose exec php vendor/bin/phpunit tests/Controller/HomeControllerTest.php` passes including the new test
- `docker compose exec php vendor/bin/phpunit` (full suite) passes
- `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff` reports no changes needed

#### Manual Verification:

- In a private/incognito browser session (or `curl` without cookies), visit each of the 10 patient-only URLs plus `/` and confirm every one ends up redirected to `/login` while logged out

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 4: Registration Email-Enumeration Fix

### Overview

Stop the registration flow from disclosing whether a submitted email is already registered, and lock the fix in with a regression test.

### Changes Required:

#### 1. Generic duplicate-email message

**File**: `src/Entity/User.php`

**Intent**: Remove the direct account-existence confirmation from the registration duplicate-email path.

**Contract**: On the class-level `#[UniqueEntity(fields: ['email'], message: '...')]` attribute (line 15), change the `message` value from `'Istnieje już konto z tym adresem e-mail.'` to `'Rejestracja nie powiodła się. Sprawdź wprowadzone dane i spróbuj ponownie.'` No other change: the violation still attaches to the `email` field (`errorPath` stays at its default), the response still gets its 422 automatically from Symfony's form-invalid handling in `AbstractController::doRender()` (message-text-independent), and no template or translation file changes are needed (this repo has no `translations/` directory — the string is inline in this one attribute).

#### 2. Regression test

**File**: `tests/Controller/RegistrationControllerTest.php`

**Intent**: Lock in the fix so the leaking message can't silently return.

**Contract**: New test `testDuplicateEmailErrorDoesNotConfirmAccountExistence`, using the same registration-then-re-register setup as the existing `testDuplicateEmailIsRejectedWithFormErrorAndNoSecondRow` (`:35-56`): assert the response body does not contain the substring `'Istnieje już konto'`; assert it does contain the new generic message text; the existing `assertResponseIsUnprocessable()` and single-DB-row assertions remain as-is (unaffected by the wording change, and left in the pre-existing test rather than duplicated here).

### Success Criteria:

#### Automated Verification:

- `docker compose exec php vendor/bin/phpunit tests/Controller/RegistrationControllerTest.php` passes, including the new test and the pre-existing `testDuplicateEmailIsRejectedWithFormErrorAndNoSecondRow` (unchanged)
- `docker compose exec php vendor/bin/phpunit` (full suite) passes
- `docker compose exec php vendor/bin/phpstan analyse` passes (`src/Entity/User.php` falls under its `src/`-only scope)
- `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff` reports no changes needed

#### Manual Verification:

- Manually submit the registration form with an email that already has an account and confirm the displayed error no longer states or implies an account already exists, while remaining distinguishable from the blank/malformed-email messages so the form stays usable

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful.

---

## Testing Strategy

### Unit Tests:

- None added — all four phases are integration-level, matching `context/foundation/test-plan.md` §3 Phase 1's "integration" test-type scope. `DiaryEntryVoterTest` is unaffected.

### Integration Tests:

- Phase 2: one new cross-account functional test (`testHistoryDoesNotExposeAnotherUsersEntries`).
- Phase 3: ten new anonymous-access functional tests across 5 controller test files.
- Phase 4: one new functional test proving the generic error message.

### Manual Testing Steps:

1. After Phase 1: review the trait-adoption diff to confirm no assertion changed meaning.
2. After Phase 2: temporarily break the `history` query's user-scoping, confirm the new test catches it, then revert.
3. After Phase 3: manually visit each of the 10 protected URLs plus `/` while logged out (incognito or `curl`) and confirm every one redirects to `/login`.
4. After Phase 4: manually attempt registration with a duplicate email and confirm the message no longer confirms account existence.

## Performance Considerations

None — this plan adds test coverage and one string constant change; no runtime code paths are altered in a way that affects performance.

## Migration Notes

None — no schema or data changes.

## References

- Research: `context/changes/testing-authorization-access-boundary/research.md`
- Existing pattern to replicate: `tests/Controller/DiaryControllerTest.php:639-645` (`testExportRequiresAuthentication`)
- Existing cross-account pattern to replicate: `tests/Controller/DiaryControllerTest.php:597-621` (`testExportOnlyIncludesRequestingUsersEntries`)
- Ownership-denial convention: `context/archive/2026-08-27-edit-delete-diary-entry/research.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Shared Diary Test-Fixture Trait

#### Automated

- [x] 1.1 phpunit tests/Controller/DiaryControllerTest.php passes with no regressions — 7acfde0
- [x] 1.2 phpunit tests/Service/Export/DiaryExportServiceTest.php passes with no regressions — 7acfde0
- [x] 1.3 Full phpunit suite passes — 7acfde0
- [x] 1.4 php-cs-fixer dry-run reports no changes needed — 7acfde0

#### Manual

- [x] 1.5 Diff review confirms no test assertion was weakened or removed during the refactor — 7acfde0

### Phase 2: Risk #1 — `history` Cross-Account Coverage

#### Automated

- [x] 2.1 phpunit tests/Controller/DiaryControllerTest.php passes including the new test — 1b86682
- [x] 2.2 Full phpunit suite passes — 1b86682
- [x] 2.3 php-cs-fixer dry-run reports no changes needed — 1b86682

#### Manual

- [x] 2.4 Breaking the history query's user-scoping makes the new test fail; reverting restores green — 1b86682

### Phase 3: Risk #5 — Unauthenticated-Access Coverage

#### Automated

- [x] 3.1 phpunit tests/Controller/OnboardingControllerTest.php passes including the new test — 1085030
- [x] 3.2 phpunit tests/Controller/DashboardControllerTest.php passes including the 3 new tests — 1085030
- [x] 3.3 phpunit tests/Controller/ProfileControllerTest.php passes including the new test — 1085030
- [x] 3.4 phpunit tests/Controller/DiaryControllerTest.php passes including the 4 new tests — 1085030
- [x] 3.5 phpunit tests/Controller/HomeControllerTest.php passes including the new test — 1085030
- [x] 3.6 Full phpunit suite passes — 1085030
- [x] 3.7 php-cs-fixer dry-run reports no changes needed — 1085030

#### Manual

- [ ] 3.8 Manually visiting each of the 10 protected URLs plus / while logged out redirects to /login

### Phase 4: Registration Email-Enumeration Fix

#### Automated

- [x] 4.1 phpunit tests/Controller/RegistrationControllerTest.php passes including the new test
- [x] 4.2 Full phpunit suite passes
- [x] 4.3 phpstan analyse passes
- [x] 4.4 php-cs-fixer dry-run reports no changes needed

#### Manual

- [ ] 4.5 Manually registering with a duplicate email no longer shows a message confirming account existence
