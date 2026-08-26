<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Diary History View (S-05)

- **Plan**: context/changes/diary-history-view/plan.md
- **Scope**: Phase 1 of 2, Phase 2 of 2 (full plan)
- **Date**: 2026-08-26
- **Verdict**: APPROVED
- **Findings**: 0 critical, 0 warnings, 2 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Findings

### F1 — `getUser()` cast is unguarded in `history()`

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Controller/DiaryController.php:65-66
- **Detail**: `/** @var User $user */ $user = $this->getUser();` has no runtime null/type guard. This is identical to the existing convention in `new()` (DiaryController.php:25-26) and is safe in practice because `#[IsGranted('ROLE_USER')]` guarantees an authenticated `User` — not a new gap introduced by this change, just noting for awareness.
- **Fix**: No action needed — matches established project convention.
- **Decision**: ACCEPTED (matches existing convention, no fix needed)

### F2 — New DTOs use plain public constructors instead of the `RatioSuggestionResult`-style private-constructor + named factories

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: src/Service/History/DiaryDayGroup.php, DiaryHistoryPage.php, src/Service/Chart/ChartPoint.php, ChartZoneBand.php, GlucoseHistoryChart.php
- **Detail**: `RatioSuggestionResult`/`HypoglycemiaWarningResult` use a private constructor with `suggest()`/`none()` static factories for their two-variant "available vs. not" shape. The five new DTOs are pure record-shaped data (no real "none" variant — empty arrays and `hasEntries`/`hasPoints` flags serve that role) and use plain public constructors instead. This is a reasonable lighter-weight variant of the existing pattern, not a defect.
- **Fix**: No action needed.
- **Decision**: ACCEPTED (appropriate variant for record-shaped DTOs, no fix needed)

## Verification Evidence

- `docker compose exec php vendor/bin/phpunit` — 92 tests, 275 assertions, all passing.
- `docker compose exec php vendor/bin/phpstan analyse` — no errors.
- `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff` — 0 of 62 files need fixing.
- Diff scope (`git diff --name-only 2504716~1..1ddbafc`) matches the plan's Phase 1 + Phase 2 "Changes Required" file lists exactly, plus expected `context/changes/diary-history-view/*` bookkeeping — no unplanned files.
- The temporary `when@test` public-service overrides added in Phase 1 (`config/services.yaml`) were confirmed removed in Phase 2 now that `DiaryController::history()` wires both services in directly.
- All Progress rows (1.1–1.3, 2.1–2.9) are `[x]` with commit SHAs (`2504716` for Phase 1, `1ddbafc` for Phase 2); manual verification items 2.4–2.9 were confirmed by the user before the Phase 2 commit.
