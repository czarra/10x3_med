<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Authorization & Access-Boundary Hardening Implementation Plan

- **Plan**: context/changes/testing-authorization-access-boundary/plan.md
- **Scope**: Phase 4 of 4 (full plan review — all phases complete)
- **Date**: 2026-08-28
- **Verdict**: APPROVED
- **Findings**: 0 critical, 1 warning, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | PASS |

## Findings

### F1 — Test naming deviates from sibling convention

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: tests/Controller/ProfileControllerTest.php:47
- **Detail**: 9 of the 10 new "requires authentication" tests added across this plan follow `test<Action>RequiresAuthentication` (matching the pre-existing `SecurityControllerTest` precedent and this plan's own `testExportRequiresAuthentication` reference pattern). `ProfileControllerTest::testProfileRequiresAuthenticationForFreshAnonymousClient` is the one outlier with a longer, differently-shaped suffix. No functional difference — every test in the batch uses a fresh client regardless.
- **Fix**: Rename to `testProfileRequiresAuthentication` to match the other 9.
- **Decision**: FIXED — renamed in tests/Controller/ProfileControllerTest.php, re-ran phpunit (2/2 pass)

### F2 — Cross-account tests use substring assertions, not DOM-scoped ones

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: tests/Controller/DiaryControllerTest.php:302-325 (`testHistoryDoesNotExposeAnotherUsersEntries`), and the pre-existing `testExportOnlyIncludesRequestingUsersEntries`
- **Detail**: Both cross-account tests assert on raw response-body substring matches (`assertStringContainsString('222', ...)` / `assertStringNotContainsString('111', ...)`) rather than a scoped DOM selector, unlike other assertions in the same file (`assertSelectorTextContains`). Not vacuous — Phase 2's manual verification step (breaking the query's scoping, confirming the test failed, then reverting) already proved this test has real signal — but a DOM-scoped assertion would be marginally more precise.
- **Fix**: Optional — no action required now; if a future test in this area needs tighter isolation, scope the assertion to `main table` or similar rather than the whole response body.
- **Decision**: SKIPPED

## Evidence

- **Plan-drift sub-agent**: all 11 planned changes across 4 phases verified MATCH against actual file content — zero drift, zero missing items, zero unplanned extras. Sanity grep confirmed the old leaking registration message (`'Istnieje już konto z tym adresem e-mail.'`) has exactly one remaining occurrence repo-wide, in the new regression test's negative assertion proving it's gone.
- **Safety/quality/pattern sub-agent**: no CRITICAL or WARNING security/reliability/data-safety issues. Every new test's cleanup verified against actual FK relationships (no orphaned-row or over-broad-DELETE risk). `src/Entity/User.php` diff confirmed to touch exactly the one message string, UTF-8 encoding consistent, no stale references to the old message elsewhere.
- **Success criteria re-verified directly**: full `phpunit` suite 141/141, `phpstan analyse` clean, `php-cs-fixer fix --dry-run --diff` 0/69 files need changes. All Progress manual items are `[x]` with observable evidence in the diff/commit history (Phase 2's break-then-revert verification is recorded in the p2 commit message).
- **Git scope check**: `git diff --name-only` across all 5 commits (7acfde0, 1b86682, 1085030, 836aa3a, 5dca242) matches the plan's file list exactly, plus the expected change-folder bookkeeping files (change.md, research.md, plan.md, plan-brief.md, reviews/plan-review.md).
