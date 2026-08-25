<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Log Diary Entry (S-02)

- **Plan**: context/changes/log-diary-entry/plan.md
- **Scope**: Full plan (Phase 1 of 2 + Phase 2 of 2)
- **Date**: 2026-08-25
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical, 2 warnings, 2 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | WARNING |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | WARNING |

## Findings

### F1 — `glycemiaMgDl` has no upper bound, risking an unhandled Postgres range error

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Entity/DiaryEntry.php:24-26
- **Detail**: `glycemiaMgDl` is validated with only `Assert\GreaterThan(20)` — no upper bound, per the plan's explicit note that this matches the PRD's "no restrictive upper limit" decision on FR-004. The column is a Postgres 32-bit `INT` (`migrations/Version20260825132206.php:23`). A value like `3000000000` is a valid PHP int and passes validation, but Postgres raises "integer out of range" on flush — an unhandled `DBALException` surfacing as a 500 instead of a form error, for what could just be a fat-fingered extra digit.
- **Fix**: Add a generous upper bound (e.g. `Assert\Range(min: 21, max: 2000)` or `Assert\LessThan(2000)`) — high enough to never reject a real glucose reading, but bounded well within Postgres `INT` range — mirroring the `Assert\Range` pattern already used for `ww`, `insulinDose`, and `activityDurationMinutes`. This converts a flush-time crash into a normal inline form error.
- **Decision**: FIXED — replaced `Assert\GreaterThan(20)` with `Assert\Range(min: 21, max: 2000)` on `DiaryEntry::$glycemiaMgDl`. `DiaryEntryTest` (9/9) and phpstan re-verified green.

### F2 — Unplanned nav restructuring and extra "Przejdź do profilu" link

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Scope Discipline
- **Location**: templates/base.html.twig:32-44
- **Detail**: The plan's contract for this file was: "Inside the existing `{% if app.user %}` nav block, add a link to `path('diary_entry_new')` labeled 'Dodaj wpis', placed before the logout form." The pre-phase-2 nav (`git show 7b0eec5:templates/base.html.twig`) was a flat single-item list containing only the logout form — no profile link, no dropdown. The shipped change (commit 53c478c) instead replaced that flat nav with a `<details class="dropdown">` hamburger menu and added a brand-new "Przejdź do profilu" link alongside "Dodaj wpis" — neither the dropdown restructuring nor the profile link was described in this plan's Changes Required. The addition is benign (it's a working, reasonable UX improvement) and all tests still pass, but it's scope beyond what was planned.
- **Fix A ⭐ Recommended**: Document the nav restructuring as an addendum note in plan.md explaining it was needed to keep the growing link list usable.
  - Strength: Preserves the shipped UX improvement; keeps the plan an accurate record of what actually exists.
  - Tradeoff: Plan becomes a slightly moving target relative to what was originally reviewed/approved.
  - Confidence: HIGH — this repo's other reviews already use addenda for this kind of discovered scope.
  - Blind spot: Whether the dropdown UX choice itself was intentionally evaluated or just convenient.
- **Fix B**: Revert the nav to a flat list with just the one planned "Dodaj wpis" link added, dropping the dropdown and the profile link.
  - Strength: Strict scope discipline, matches the plan's literal instruction.
  - Tradeoff: Throws away a harmless, arguably useful working feature (in-nav profile access) for no real benefit.
  - Confidence: MEDIUM — no evidence anyone objects to the dropdown; reverting is probably not wanted here.
  - Blind spot: Unknown whether removing the profile nav link would regress any expectation set elsewhere (e.g. UX flows relying on it).
- **Decision**: FIXED via Fix A — added an "## Addenda" section to plan.md documenting the nav restructuring and profile-link addition as an accepted deviation.

### F3 — `DiaryEntry` constructor allows a transiently-invalid entity outside the form path

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Entity/DiaryEntry.php:56-64
- **Detail**: The constructor deliberately defaults `glycemiaMgDl = 0` (invalid) and `measuredAt = now`, relying on the form/validator to make the entity valid before persist — confirmed intentional by `tests/Entity/DiaryEntryTest.php:129-138`. This differs from `PatientProfile`'s constructor, which requires all business-meaningful scalars up front and can never be constructed invalid. Currently harmless — the only call site (`DiaryController`) always checks `$form->isValid()` before persisting — but it's a latent trap for any future non-form call site (fixture, console command, admin tool, API) that persists a `DiaryEntry` directly.
- **Fix**: No action required now. If a non-form call site is ever added, either require `glycemiaMgDl`/`measuredAt` as constructor arguments or add a short comment on the constructor documenting the deliberate transient-invalid-state pattern.
- **Decision**: FIXED (differently) — per user request to close the trap rather than just document it, `glycemiaMgDl` and `measuredAt` are now required constructor arguments (`__construct(User $user, int $glycemiaMgDl, \DateTimeImmutable $measuredAt, float $insulinWwRatioSnapshot, float $baseDoseSnapshot)`), matching the existing `PatientProfile`/`OnboardingController` precedent where the caller explicitly passes the deliberate placeholder (`new PatientProfile($user, 0, 0)`) instead of the entity defaulting it internally. `DiaryController::new()` now calls `new DiaryEntry($user, 0, new \DateTimeImmutable(), ...)`. Any future non-form call site must now consciously choose these values. Full suite (32/32), phpstan, and php-cs-fixer re-verified green.

### F4 — Range boundary tests only cover the upper bound

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: tests/Entity/DiaryEntryTest.php:51-99
- **Detail**: `testWwRangeBoundaries`, `testInsulinDoseRangeBoundaries`, and `testActivityDurationRangeBoundaries` each assert only the upper boundary (max fails one above, passes at max). The lower boundary specified by the same `Assert\Range` constraints — `ww`/`insulinDose` should pass at 0 and fail below it, `activityDurationMinutes` should pass at 1 and fail at 0 — is never exercised.
- **Fix**: Add symmetric lower-boundary assertions to each of the three tests, mirroring the existing upper-boundary pattern.
- **Decision**: FIXED — added lower-boundary assertions (negative fails / 0 passes for `ww` & `insulinDose`; 0 fails / 1 passes for `activityDurationMinutes`) to all three tests. `DiaryEntryTest` re-verified green (9/9, 24 assertions, up from 18).
