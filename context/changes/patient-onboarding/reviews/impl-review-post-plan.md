<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Patient Onboarding (S-01) — Post-plan polish

- **Plan**: context/changes/patient-onboarding/plan.md
- **Scope**: Post-plan work (2 commits after all 4 phases + first impl-review were complete: `a5215c0` Pico.css vendoring + logout-redirect fix, `153532b` removal of unused home/status skeleton + `/` → `/profil` redirect). Not tied to a specific plan phase — requested directly in conversation after the plan was fully implemented and reviewed.
- **Date**: 2026-08-25
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical, 3 warnings, 0 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | WARNING |
| Safety & Quality | PASS |
| Architecture | WARNING |
| Pattern Consistency | PASS |
| Success Criteria | WARNING |

## Findings

### F1 — deploy-plan.md's health-check path now points at a deleted endpoint

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Architecture
- **Location**: context/deployment/deploy-plan.md:111-112
- **Detail**: `deploy-plan.md` specifies `deploy.healthcheckPath: "/api/status"`, reusing "the existing `src/Controller/Api/StatusController.php` endpoint — already checks DB connectivity" as the platform health check. That endpoint was just deleted (commit `153532b`) as part of this round's cleanup. No `.github/workflows/deploy.yml` or other deploy tooling exists yet — `deploy-plan.md` is still a planning document, not yet acted on — so nothing is broken *today*, but whoever implements the deploy plan next will wire a health check against a 404.
- **Fix A ⭐ Recommended**: Update `deploy-plan.md`'s health-check section to point at a route that still exists and still validates the app is healthy — e.g. `/login` (200 for anon, cheap, no DB touch) or restore a minimal `/api/status`-equivalent endpoint if DB-connectivity-in-healthcheck is actually load-bearing for the deploy strategy.
  - Strength: Keeps the deploy plan internally consistent for whenever it's implemented; doesn't require restoring code nobody wants.
  - Tradeoff: Loses the DB-connectivity check specifically (a plain 200 on `/login` doesn't prove the DB is reachable) — worth explicitly deciding whether that matters for the deploy strategy `infrastructure.md` chose.
  - Confidence: MED — haven't read `infrastructure.md`'s full reasoning for why DB-connectivity-in-healthcheck was chosen originally.
  - Blind spot: Whether the target platform's health-check semantics (e.g. does a failed health check kill/restart the container, or just mark it unready) make the DB-check distinction actually consequential.
- **Fix B**: Leave `deploy-plan.md` as-is; note it as a known gap to resolve when the deploy plan is actually implemented (its own `/10x-plan` review pass would presumably catch a 404 health-check path at that point).
  - Strength: Zero effort now; deploy-plan.md isn't live code, so nothing breaks today.
  - Tradeoff: Relies on someone remembering/catching this later — a plan document silently referencing dead code is exactly the kind of drift this review exists to catch.
  - Confidence: MED — depends on how carefully the deploy plan gets re-validated before it's actually implemented.
  - Blind spot: None significant.
- **Decision**: FIXED (via Fix A) — updated `deploy-plan.md:111-116` to point `deploy.healthcheckPath` at `/login` instead of the deleted `/api/status`, with a note explaining that DB connectivity is already gated by the `preDeployCommand` migration step, so the health check itself only needs to prove the app process is up.

### F2 — Stale references to deleted files in AGENTS.md and roadmap.md

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Scope Discipline
- **Location**: AGENTS.md:13, context/foundation/roadmap.md:76-77
- **Detail**: `AGENTS.md` still says "API endpoints live under `src/Controller/Api/` (see `StatusController.php`)" and `roadmap.md`'s current-state section still lists `templates/home/index.html.twig` and `Api/StatusController` as existing. Both files are gone. (`context/changes/auth-scaffold/plan.md`'s reference to the same files is historical narrative describing state at planning time — that one is fine and shouldn't change.)
- **Fix**: Update `AGENTS.md:13` to drop the `Api/StatusController.php` example (or point at whatever becomes the next real example of an API endpoint, if any exists later), and update `roadmap.md:76-77`'s current-state bullets to reflect that the home page and status endpoint no longer exist.
- **Decision**: PARTIALLY FIXED — `AGENTS.md:13` updated to reference `HomeController.php`/`SecurityController.php` instead of the deleted `StatusController.php`. `roadmap.md:76-77` left as-is on reconsideration: it sits inside a `## Baseline` section explicitly dated "Stan repozytorium na dzień 2026-08-22" — a historical snapshot the Foundations/Slices below it are scoped against, analogous to `auth-scaffold/plan.md`'s Current State Analysis. Editing it would falsify the 2026-08-22 record rather than fix a live-doc staleness issue. User confirmed leaving it alone.

### F3 — No test coverage for the new `/` → `/profil` redirect

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: src/Controller/HomeController.php:14 (no corresponding test file)
- **Detail**: `HomeController::index()` now redirects `/` to `patient_profile`. The redirect's correctness was verified by both review agents via code trace (it correctly cascades to `/login` for anonymous visitors and `/onboarding` for authenticated-no-profile users, through the existing `access_control` and `RequireOnboardingSubscriber` mechanisms), but there's no `WebTestCase` covering it — every other route added in this plan has a corresponding functional test.
- **Fix**: Add a small test (e.g. `tests/Controller/HomeControllerTest.php`) asserting `GET /` redirects to `/profil` for an authenticated user with a profile — the two cascade cases (anonymous → `/login`, no-profile → `/onboarding`) are already exercised indirectly by the existing `/profil`-focused tests in `SecurityControllerTest`/`OnboardingControllerTest`, so one direct test for the new behavior is enough.
- **Decision**: FIXED — added `tests/Controller/HomeControllerTest.php::testHomeRedirectsAuthenticatedUserToProfile`, asserting `GET /` redirects to `/profil` for an authenticated user with a profile. Full suite re-verified green (19/19), phpstan/cs-fixer clean.
