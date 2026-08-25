<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Log Diary Entry (S-02)

- **Plan**: context/changes/log-diary-entry/plan.md
- **Scope**: Full plan (Phase 1 of 2 + Phase 2 of 2) — re-review after prior triage fixes (commit `29f1cb4`)
- **Date**: 2026-08-25
- **Verdict**: APPROVED
- **Findings**: 0 critical, 0 warnings, 2 observations

This is a follow-up full-plan review. A prior review found 4 findings (F1–F4:
unbounded `glycemiaMgDl`, unplanned nav restructuring, a constructor
trap, thin range-boundary tests); all four were triaged and fixed in
commit `29f1cb4` (pushed to `origin/main`), and the two accepted
deviations are recorded in `plan.md`'s `## Addenda` section. This pass
re-verifies those fixes on the current code and checks for anything new.
**No new drift and no new correctness issues were found** — only two minor,
non-actionable-today observations below.

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | WARNING |
| Pattern Consistency | PASS |
| Success Criteria | WARNING |

## Findings

### F1 — `DiaryEntry` constructor has two adjacent same-typed float parameters with no compiler-enforced distinction

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Architecture
- **Location**: src/Entity/DiaryEntry.php:56; src/Controller/DiaryController.php:30
- **Detail**: The constructor's last two parameters, `float $insulinWwRatioSnapshot` and `float $baseDoseSnapshot`, are the same type in adjacent positions. All current call sites (`DiaryController.php:30`, `DiaryEntryTest.php:164,193`) pass them in the correct order, verified against `PatientProfile::getInsulinWwRatio()`/`getBaseDose()`. But a future positional swap at any call site would type-check silently — PHP has no `strict_types` distinction between two same-typed floats, and this codebase doesn't declare `strict_types` anywhere (pre-existing convention, not introduced here). Only a behavioral test or manual review would catch a swap.
- **Fix**: No action required today — all call sites are correct and covered by tests that assert the actual snapshot values match the profile. If this constructor gains more float/int parameters later, consider named arguments at call sites (`new DiaryEntry(user: $user, ...)`) to make a positional swap impossible.
- **Decision**: FIXED — converted all three `new DiaryEntry(...)` call sites (`DiaryController.php:30`, `DiaryEntryTest.php` `buildEntry()` and `testConstructorDefaultsAreDeliberatelyInvalidUntilFormFillsThemIn`) to named arguments, making a positional swap of `insulinWwRatioSnapshot`/`baseDoseSnapshot` structurally impossible. Full suite (32/32), phpstan, and php-cs-fixer re-verified green.

### F2 — No test exercises `glycemiaMgDl`'s new upper bound

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Success Criteria
- **Location**: tests/Entity/DiaryEntryTest.php:13-25
- **Detail**: The prior review's F1 fix added `Assert\Range(min: 21, max: 2000)` to `glycemiaMgDl` (previously unbounded). `testGlycemiaBoundary` still only asserts the original lower boundary (20 fails, 21 passes) — no test asserts 2000 passes / 2001 fails, so the new upper bound has no regression coverage.
- **Fix**: Add a symmetric upper-boundary assertion to `testGlycemiaBoundary` (or a new test): `glycemiaMgDl = 2000` passes, `2001` fails.
- **Decision**: FIXED — added upper-boundary assertions (2000 passes, 2001 fails) to `testGlycemiaBoundary`. `DiaryEntryTest` re-verified green (9/9, 26 assertions, up from 24).
