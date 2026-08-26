<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: S-03 Sugestia skorygowanego przelicznika insulina/WW

- **Plan**: context/changes/insulin-ww-ratio-suggestion/plan.md
- **Scope**: All 6 phases (full plan)
- **Date**: 2026-08-26
- **Verdict**: REJECTED
- **Findings**: 1 critical, 2 warnings, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | WARNING |
| Scope Discipline | PASS |
| Safety & Quality | FAIL |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Findings

### F1 — Division-by-zero / NaN corruption in ratio-suggestion pairing

- **Severity**: CRITICAL
- **Impact**: MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: src/Service/Suggestion/InsulinWwRatioSuggestionService.php:69 (magnitude calc), :99 (buildMealPairs)
- **Detail**: The magnitude step divides `nadwyzka / $ww`, where `$ww` comes from `DiaryEntry::getWw()` — nullable float, validated only with an upper bound (`Assert\Range(min: 0, max: 20)`). `0` is a legitimate value (protein-only meal, correction-only bolus) that a user can enter via the diary form. `buildMealPairs()` only excludes `ww === null`, not `ww === 0.0`, so a zero-WW meal can enter pairing and produce `INF`/`NaN`, which then propagates through averaging and the min/max clamp (PHP's NaN comparisons are unreliable). `DashboardController::acceptRatio` persists the result via setter + `flush()` **without re-running the Symfony Validator** (unlike `ProfileController`, which validates through a Form) — so a NaN/INF value could be written straight into `patient_profiles.insulin_ww_ratio`, corrupting the dosing baseline for a medical-adjacent field. No test covers `ww = 0`.
- **Fix**: In `buildMealPairs()`, exclude meal entries with `ww <= 0.0` from pairing (same treatment as "no after-reading found" — unpairable), and add a regression test for `ww = 0`.
  - Strength: Reuses the exclusion pattern already in place for unmatched pairs; scoped to the one function that constructs pairs, no change to `suggestFor()`'s public contract.
  - Tradeoff: A protein-only/correction-only entry will never contribute to a ratio suggestion, even if its glycemia delta is meaningful — consistent with the algorithm's carb-ratio purpose, but worth a quick confirm with the product owner.
  - Confidence: HIGH — the null-check already exists at this exact call site; widening it to `<= 0.0` is a one-line, low-risk change.
  - Blind spot: Haven't checked whether existing seed/test data relies on `ww = 0` entries pairing successfully today.
- **Decision**: FIXED — excluded `ww <= 0.0` from `buildMealPairs()` (src/Service/Suggestion/InsulinWwRatioSuggestionService.php:99) and added `testZeroWwMealIsExcludedFromPairing` regression test.

### F2 — Off-by-one boundary bug can silently drop the most recent day from the base-dose streak scan

- **Severity**: WARNING
- **Impact**: MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Adherence
- **Location**: src/Service/Suggestion/BaseDoseSuggestionService.php:122-159 (findMostRecentRun)
- **Detail**: `$cursor` steps forward one whole day at a time while preserving time-of-day. `$startDate`'s time-of-day comes from either the first entry or `$cutoffDate + 1 day` (the time a *prior suggestion was accepted*, unrelated to any diary entry), while `$maxDate` is the literal timestamp of the last entry. If the last entry's time-of-day is earlier than `$cursor`'s inherited time-of-day on the same calendar date, `while ($cursor <= $maxDate)` exits one iteration early and silently drops the most recent calendar day from the scan — a real, qualifying suggestion could go undetected. Untested: every fixture in `BaseDoseSuggestionServiceTest.php` uses a uniform `setTime(7, 0)`, so `$cursor` and `$maxDate` always share time-of-day there. In production, `acceptedAt` is `new \DateTimeImmutable()` at click time and diary entries carry whatever time the user logged them, so the mismatch is reachable.
- **Fix**: Normalize both `$cursor` and `$maxDate` to midnight (or compare via `->format('Y-m-d')`) before the day-stepping loop, so the loop compares calendar dates only.
  - Strength: Removes the whole class of time-of-day boundary bugs in one change, matching the algorithm's stated "calendar date" semantics exactly.
  - Tradeoff: Requires re-checking that nothing else inside the loop body relies on the preserved time-of-day.
  - Confidence: MED — the fix direction is clear; the full blast radius inside the method hasn't been traced line by line.
  - Blind spot: Haven't verified whether any other part of `BaseDoseSuggestionService` also assumes a non-midnight cursor time.
- **Decision**: FIXED — normalized `$cursor`/`$maxDate` to midnight in `findMostRecentRun` (src/Service/Suggestion/BaseDoseSuggestionService.php:131-132) and added `testStreakIncludesLastDayEvenWhenItsEntryTimeIsEarlierThanTheFirstDaysEntry`, verified to fail without the fix and pass with it.

### F3 — Suggested values persisted without re-running entity validation

- **Severity**: WARNING
- **Impact**: LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Controller/DashboardController.php:64, 101
- **Detail**: Both accept actions write via `PatientProfile` setter + `flush()`, relying solely on the service's manual clamp rather than the Symfony Validator that `ProfileController::edit` runs through a Form. The clamp bounds match the entity's `Assert` constraints today, so this isn't independently exploitable — but it's the safety net that would have caught F1's NaN/INF value before it reached the database, and silently stops protecting if the clamp bounds and entity constraints ever drift apart.
- **Fix**: Validate the profile via the injected `ValidatorInterface` before `flush()` in both accept actions; on failure, redirect without writing (mirroring the existing "no longer available" race-handling path).
- **Decision**: FIXED — injected `ValidatorInterface` into both `acceptRatio`/`acceptBaseDose`; redirects with no history persist / no flush / no flash if `validate($profile)` returns violations (src/Controller/DashboardController.php).

### F4 — No composite index for history-table cutoff lookups

- **Severity**: OBSERVATION
- **Impact**: LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality (performance)
- **Location**: migrations/Version20260826080806.php:24,26
- **Detail**: `findLatestByUser()` on both history repositories filters by `user_id` and sorts by `accepted_at DESC LIMIT 1`, but only `user_id` is indexed. Non-issue at MVP data volumes.
- **Fix**: Add a composite `(user_id, accepted_at)` index in a follow-up migration if history rows grow large per user.
- **Decision**: FIXED — added `#[ORM\Index(columns: ['user_id', 'accepted_at'])]` to both history entities and generated/applied migration `Version20260826092028` on dev and test DBs.

## Notes for the record (not findings — no action needed)

- Both agents confirmed all 8 worked numeric examples (4 ratio + 4 base-dose) reproduce exactly when hand-traced against the actual code.
- No MISSING or EXTRA files versus the plan's file list; no unplanned scope creep.
- CSRF handling, IDOR scoping, authn/authz, migration reversibility, and pattern consistency (constructors, repository shape, CSRF form pattern) all checked out clean.
- The float→int migration's `down()` widens the column type back to `DOUBLE PRECISION` without restoring lost fractional precision — inherent to a narrowing conversion, not a fixable gap.
