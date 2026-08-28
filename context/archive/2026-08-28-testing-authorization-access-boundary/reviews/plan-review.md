<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Authorization & Access-Boundary Hardening Implementation Plan

- **Plan**: context/changes/testing-authorization-access-boundary/plan.md
- **Mode**: Deep
- **Date**: 2026-08-28
- **Verdict**: SOUND
- **Findings**: 0 critical, 1 warning, 0 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | PASS |
| Plan Completeness | WARNING |

## Grounding

12/12 paths ✓ (DiaryController.php, DiaryEntryVoter.php, DiaryEntryRepository.php, DiaryControllerTest.php, DiaryExportServiceTest.php, OnboardingControllerTest.php, DashboardControllerTest.php, ProfileControllerTest.php, HomeControllerTest.php, RegistrationControllerTest.php, User.php, security.yaml), 10/10 symbols ✓ (UniqueEntity message, test method names/line numbers, route+IsGranted pairs, CSRF token ids, HomeController redirect logic, RegistrationControllerTest existing fixture pattern), brief↔plan ✓.

Additional direct verification performed (Step 3, in-line rather than sub-agent, given the plan's own research.md was produced this session at the same commit):
- `src/Controller/HomeController.php` confirmed to unconditionally redirect `/` → `patient_profile` regardless of auth state — validates Phase 3's two-hop home-chain test design.
- `tests/Controller/RegistrationControllerTest.php` read in full — confirmed Phase 4's planned test can reuse the exact existing `testDuplicateEmailIsRejectedWithFormErrorAndNoSecondRow` setup pattern without new fixture code.
- Confirmed the leaking string `'Istnieje już konto z tym adresem e-mail.'` appears in exactly one place in the repo (`src/Entity/User.php:15`), matching research.md.
- Confirmed `DiaryController::edit`/`delete` route `{id}` parameter has no routing-level regex requirement, so the anonymous-access tests' literal `1` placeholder is safe.
- Blast-radius sweep on `createUser`/`cleanup(User)` found the duplicated fixture pattern actually spans 12 test files, not the 6 named in the plan's Current State Analysis — doesn't change Phase 1's scope (still correctly limited to the 2 files this phase touches) but the plan's own count of affected files was an undercount, folded into F1 as a documentation-accuracy note rather than a separate finding.

## Findings

### F1 — Protected-route count is off by one throughout the plan

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Overview, Current State Analysis, Desired End State, Phase 3 manual verification, Testing Strategy, Progress 3.8
- **Detail**: There are 10 protected controller actions behind `security.yaml`'s `access_control` rule (onboarding, profile, dashboard, accept_ratio, accept_base_dose, new, edit, delete, history, export) — verified by grepping each controller's `#[Route]`/`#[IsGranted]` pair. The plan said "9" in six places, and Current State Analysis's "the other 7 actions" actually listed 8 route names. The concrete Phase 3 deliverable list (9 new tests: onboarding ×1, dashboard ×3, profile ×1, diary ×4) was itself correct and complete — this was a prose/count error, not a missing test or a structural gap.
- **Fix**: Correct the counts in all 6 locations — "9" → "10" total protected actions (9 of them needing new coverage this phase, since export was already covered), "the other 7 actions" → "the other 8 actions".
- **Decision**: FIXED (applied directly to plan.md)
