<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Patient Onboarding (S-01)

- **Plan**: context/changes/patient-onboarding/plan.md
- **Scope**: Phase 4 of 4 (full plan review)
- **Date**: 2026-08-25
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical, 3 warnings, 2 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | WARNING |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | PASS |

## Findings

### F1 — Unhandled unique-constraint race on double-submit

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: src/Controller/RegistrationController.php:24-32, src/Controller/OnboardingController.php:27-35
- **Detail**: Both controllers do a pre-check (`UniqueEntity` validation / `findOneByUser`) then `persist()`+`flush()` with no try/catch. Two concurrent requests — a double-click on submit, two tabs, or a race between duplicate-email registrations — can both pass the pre-check before either commits, then the second `flush()` throws an uncaught `UniqueConstraintViolationException` → 500. `tests/Entity/PatientProfileTest.php:41` already documents that this exception propagates uncaught for `PatientProfile`, confirming no handling exists anywhere in the app for this class of race. Low probability, but a real gap under concurrent traffic (e.g. a patient double-clicking "Submit" on a slow connection).
- **Fix A ⭐ Recommended**: Catch `Doctrine\DBAL\Exception\UniqueConstraintViolationException` around the `flush()` call in both controllers, re-check via a fresh query, and either flash a friendly "already exists" message and redirect, or silently redirect to the now-existing resource (`/profil` or `/onboarding`) since the outcome the user wanted (an account/profile existing) is already true.
  - Strength: Turns a rare 500 into a graceful redirect; matches the "no 500 on duplicate" intent the plan already established for the non-race case.
  - Tradeoff: Touches two controllers; needs a decision on user-facing copy for the caught case.
  - Confidence: MED — the race is real but narrow (needs near-simultaneous requests); haven't reproduced it under load, only reasoned about it from the code.
  - Blind spot: Haven't checked whether the DB transaction isolation level makes this race easier or harder to trigger in practice.
- **Fix B**: Accept as a known, low-probability limitation and leave undocumented in code (add a one-line comment only).
  - Strength: Zero implementation cost; matches "don't build for hypothetical edge cases" instinct for an MVP with a small user base.
  - Tradeoff: A double-click under a slow connection will show a raw error page instead of a friendly message — bad first impression on the exact screens (register/onboarding) most likely to see accidental double-submits.
  - Confidence: MED — plausible but genuinely rare in practice.
  - Blind spot: No production traffic data yet to estimate actual likelihood.
- **Decision**: FIXED (via Fix A) — caught `UniqueConstraintViolationException` around `flush()` in both `RegistrationController::register()` (redirects to `app_login`) and `OnboardingController::index()` (redirects to `patient_profile`). phpstan/cs-fixer/full suite re-verified green.

### F2 — Unplanned template additions not reflected in the plan

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Scope Discipline
- **Location**: templates/base.html.twig, templates/security/login.html.twig
- **Detail**: A global "Wyloguj się" (logout) nav link on every page (`base.html.twig`) and a "Zarejestruj się" link on the login page were added during Phase 4's manual-verification pass, explicitly requested by the user mid-implementation. Functionally correct and already committed (3f41f35), but neither file is mentioned in any phase's "Changes Required" section, so the plan no longer fully describes what was built.
- **Fix**: Append a short addendum note to Phase 4's "Changes Required" (or a new "Post-implementation additions" subsection) documenting these two template changes and why they were added.
- **Decision**: FIXED — added a "4. Post-implementation additions" item to Phase 4's Changes Required in plan.md documenting both files and the manual-testing feedback that prompted them.

### F3 — ProfileControllerTest doesn't follow the sibling test helper pattern

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: tests/Controller/ProfileControllerTest.php:12-51
- **Detail**: `OnboardingControllerTest`, `SecurityControllerTest`, and `RegistrationControllerTest` all extract `createUser()`/`cleanup*()` private helper methods. `ProfileControllerTest` inlines user/profile creation and cleanup directly in its one test method instead. Harmless today (single test method) but will make the file inconsistent with its siblings the moment a second test is added.
- **Fix**: Extract `createUser()`/`cleanupUser()` helpers in `ProfileControllerTest.php` to match the other three controller test files.
- **Decision**: FIXED — extracted `entityManager()`, `createUser()`, `cleanupUser()` helpers matching the sibling test files' pattern. Re-verified: test passes, cs-fixer clean.

### F4 — Logout reachable via CSRF-less GET

- **Severity**: ℹ️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: config/packages/security.yaml:24-25, src/Controller/SecurityController.php (logout action)
- **Detail**: `logout: { path: app_logout }` has no CSRF protection configured, and logout is triggered via plain `GET /logout` (confirmed by `SecurityControllerTest.php:78`). An attacker can force a session logout via a forged link or embedded image — low impact (annoyance/DoS-style, no data exposure), but Symfony supports CSRF-protecting logout and it isn't enabled here.
- **Fix**: If desired, add `enable_csrf: true` (or a `csrf_token_generator`) to the `logout:` block and switch the logout link to a POST form with a CSRF token. Given the low impact and small MVP user base, this is optional — noted for awareness rather than urged.
- **Decision**: FIXED — added `enable_csrf: true` to `logout:` in security.yaml (the existing `logout` stateless CSRF token id in config/packages/csrf.yaml already anticipated this); converted the nav logout link to a POST form with `csrf_token('logout')`; restricted `app_logout` route to `methods: ['POST']`. Updated `SecurityControllerTest`'s logout test to submit the form instead of a raw GET, and fixed a knock-on issue: the new nav `<form>` on authenticated pages made `crawler->filter('form')` ambiguous in `OnboardingControllerTest`/`ProfileControllerTest` (picked the nav form instead of the profile form) — scoped those selectors to `body > form`. Full suite re-verified green (18/18), phpstan/cs-fixer clean.

### F5 — Access-gate subscriber bounces authenticated-no-profile users away from /login and /register too

- **Severity**: ℹ️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Architecture
- **Location**: src/EventSubscriber/RequireOnboardingSubscriber.php:16 (EXCLUDED_ROUTES)
- **Detail**: The exclusion list only covers `patient_onboarding` and `app_logout`. An already-authenticated user without a profile who navigates to `/login` or `/register` gets redirected to `/onboarding` instead of seeing those pages. This is very likely intentional (prevents a half-onboarded user from re-registering or re-logging-in), and isn't a security bypass — just flagging it as a deliberate-looking behavior that isn't explicitly called out in the plan, in case it wasn't a conscious choice.
- **Fix**: No code change needed if this is the intended behavior (it reads as intended). If not, add `app_login`/`app_register` to `EXCLUDED_ROUTES`.
- **Decision**: DISMISSED (confirmed intended) — bouncing an authenticated-but-not-onboarded user away from `/login`/`/register` is the desired behavior. No code change.
