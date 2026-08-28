# Recommendation-Algorithm Edge-Case Test Coverage Implementation Plan

## Overview

Close the boundary/edge-case unit-test gaps on `BaseDoseSuggestionService` and
`HypoglycemiaWarningService` identified by research for rollout Phase 2 (risk
#2 — recommendation algorithms producing an incorrect medical suggestion on
boundary/incomplete data). `InsulinWwRatioSuggestionService` already has
complete coverage and needs no changes.

## Current State Analysis

Three suggestion/warning services exist under `src/Service/`, each with an
existing PHPUnit suite under `tests/Service/`:

- `InsulinWwRatioSuggestionService` — 9 tests, **no gaps** (confirmed by
  research: minimum-pairs, both directions, min/max step clamp, entity-range
  clamp, mixed-direction exclusion, unmatched-meal exclusion, zero-WW
  exclusion, re-trigger cutoff all covered).
- `BaseDoseSuggestionService` — 10 tests, but the `FASTING_GAP_HOURS` branch
  (`src/Service/Suggestion/BaseDoseSuggestionService.php:101-102`) has **zero
  real coverage** (every existing fixture leaves `ww`/`insulinDose` unset, so
  only the vacuous branch runs), plus 7 other itemized gaps (empty history,
  `BAND_HIGH`/`BAND_LOW` exact boundaries, direction-flip streak reset, a
  streak longer than 3 days, the lower-bound dose clamp, and same-calendar-day
  dedup) that expand to 11 new test methods in total (some gap rows need two
  tests — an exact-boundary case and a just-past-boundary case). The
  symmetric `STEP_CLAMP_MIN` bound is **not** one of these — see Key
  Discoveries below.
- `HypoglycemiaWarningService` — 12 tests, thorough, with one gap: no test
  where an insulin-adjusted `projectedGlycemia` lands exactly on a threshold.

Full gap inventory, PRD-grounding, and oracle-problem verification:
`context/changes/testing-recommendation-algorithm-edge-cases/research.md`.

## Desired End State

Both `BaseDoseSuggestionServiceTest.php` and `HypoglycemiaWarningServiceTest.php`
exercise every branch identified in the research doc's gap tables that is
reachable via clinically-valid input (see Key Discoveries for the two
excluded branches). Running `docker compose exec php vendor/bin/phpunit`
passes with the new tests included, `phpstan analyse` and `php-cs-fixer fix
--dry-run` stay clean, and `context/foundation/test-plan.md`'s risk #2
hot-spot evidence reflects the verified commit count.

### Key Discoveries:

- `BaseDoseSuggestionService.php:66-68` — given today's constants
  (`BAND_HIGH/LOW = TARGET_GLYCEMIA ± 25`, `SuggestionScaling::FACTOR = 0.02`,
  strict `>`/`<` classification), the minimum qualifying per-day excess is 26
  mg/dL, giving `krokRaw = 26 * 0.02 = 0.52`, which already exceeds
  `MIN_MAGNITUDE = 0.5`. This makes the `MIN_MAGNITUDE` exclusion branch
  practically unreachable through the public API under current constants —
  confirmed by user decision: document with a comment, do not write a test
  that can't be driven through `suggestFor()`.
- `BaseDoseSuggestionService.php:64` (`STEP_CLAMP_MIN`) has the same
  unreachability problem, discovered during plan review: `DiaryEntry`
  declares `#[Assert\Range(min: 21, max: 2000)]` on `glycemiaMgDl`
  (`src/Entity/DiaryEntry.php:25`), enforced by `DiaryEntryFormType` in the
  real application. The most negative average excess reachable with a
  clinically-valid (form-submittable) glycemia is -99 (all three fasting
  days at the floor, 21 mg/dL), giving `krokRaw = -1.98` — which never
  crosses `STEP_CLAMP_MIN = -2.0`, so the clamp never fires. Critically,
  `round(-1.98) = -2` — the same value the clamp would have produced — so a
  test using realistic low values would pass identically whether or not the
  clamp executes, giving zero regression protection. Proving real clamping
  needs an average glycemia around -5 mg/dL, which no patient could ever
  submit through the real form. Per the same reasoning already applied to
  `MIN_MAGNITUDE`: document with a comment, do not write a test for this
  branch either. (The mirror high-side clamp, `STEP_CLAMP_MAX`, has no such
  problem — the range ceiling of 2000 gives ample headroom to reach a
  clearly-distinguishing value, as the existing
  `testLargeExcessClampsStepToTwoUnits` already does.)
- `BaseDoseSuggestionService.php:87-115` (`buildFastingCandidates`) keeps
  only the first entry per calendar date (`!isset($seenDates[$dateStr])`,
  L96-98) — untested today.
- `BaseDoseSuggestionService.php:122-164` (`findMostRecentRun`) always
  returns the **most recent** qualifying run, not the first — a streak of 4+
  days needs a test that distinguishes "most recent 3" from "first 3" by
  using different glycemia values across the streak.
- The base-dose low-fasting-glycemia direction has no PRD text (PRD only
  states the high-glucose example, `prd.md:116`) — per user decision, the
  new/updated tests for this path must be documented as verifying
  *implemented* behavior, not a PRD-mandated rule.
- `context/foundation/test-plan.md`'s Risk Map cites "hot-spot dir
  `src/Service` (18 commits/30d)" for risk #2; research verified the real
  count is 6 commits/30d, only 3 of which touch Suggestion/Warning code —
  per user decision, this plan corrects that figure.

## What We're NOT Doing

- Not touching `InsulinWwRatioSuggestionService` or its test file — already
  fully covered.
- Not writing a test for the `MIN_MAGNITUDE` exclusion branch — it is not
  reachable through the public API under today's constants; documented with
  a source comment instead (per user decision).
- Not writing a test for the `STEP_CLAMP_MIN` branch — discovered during plan
  review to have the same unreachability problem as `MIN_MAGNITUDE` (see Key
  Discoveries): proving it requires an average glycemia no patient could
  ever submit through the real form. Documented with a source comment
  instead, alongside the `MIN_MAGNITUDE` note.
- Not opening a PRD-update change for the base-dose low-direction symmetry
  gap — documented as implementation-verified behavior in this plan instead
  (per user decision).
- Not modifying `context/foundation/test-plan.md`'s Phased Rollout status
  table (§3) or `context/foundation/roadmap.md` — no roadmap entry exists for
  this change, and the rollout status field is owned by the `/10x-test-plan`
  orchestration, not this plan.
- Not adding integration/functional tests — this risk's cheapest useful
  layer is unit (confirmed by research); no controller or HTTP surface is in
  scope.

## Implementation Approach

Add new test methods to the two existing test files, following the exact
patterns already established in each (`KernelTestCase` + local
user/profile/entry helpers for `BaseDoseSuggestionServiceTest`; plain
`TestCase` + local entry helper for `HypoglycemiaWarningServiceTest`). No new
fixtures, traits, or infrastructure are needed. One source comment is added
to `BaseDoseSuggestionService.php` documenting the `MIN_MAGNITUDE`
reachability finding. One factual correction is made to
`context/foundation/test-plan.md`.

## Critical Implementation Details

**Fixture construction for the `FASTING_GAP_HOURS` branch**: every existing
`BaseDoseSuggestionServiceTest` fixture entry leaves `ww` and `insulinDose`
unset (`null`), so `buildFastingCandidates()`'s `$lastInsulinBearingAt` stays
`null` throughout the whole existing suite, and only the vacuous branch
(`null === $lastInsulinBearingAt`) ever executes. The two new tests for this
branch must call `DiaryEntry::setInsulinDose()` (or `setWw()`) on a marker
entry to make `$lastInsulinBearingAt` non-null, then place a fasting
candidate entry either ≥ 6h after it (qualifies) or < 6h after it (excluded)
using `createFastingEntryAt()` (already present in the test file) to control
exact time-of-day.

**Streak "most recent 3, not first 3" test**: `findMostRecentRun()` keeps
overwriting `$bestRun` as a longer streak continues (L155-157), so a 4+ day
streak with uniform glycemia values would pass regardless of whether the
implementation used the first or most recent 3 days — the test must use
*different* glycemia values across the streak so the two possible 3-day
windows produce different `suggestedBaseDose` values, and assert the value
that only the most-recent-3 window produces.

## Phase 1: Base-Dose Boundary & Exclusion Coverage

### Overview

Add 11 new test methods to `BaseDoseSuggestionServiceTest.php` covering
every gap that's reachable via clinically-valid input, and document the
`MIN_MAGNITUDE`/`STEP_CLAMP_MIN` reachability finding in the source file.

### Changes Required:

#### 1. Base-dose suggestion test suite

**File**: `tests/Service/Suggestion/BaseDoseSuggestionServiceTest.php`

**Intent**: Prove every previously-uncovered branch of `suggestFor()` and its
private helpers behaves correctly at its boundary, following the file's
existing one-scenario-per-test convention (each new test uses the file's
existing `createUser`/`createProfile`/`createFastingEntry`/
`createFastingEntryAt` helpers — no new helpers needed except where noted).

**Contract**: Add the following test methods:

- `testEmptyHistoryYieldsNone` — a user/profile with zero diary entries;
  assert `result->available` is `false`. Covers `suggestFor()`'s first-line
  early return (`[] === $entries`), currently 0% covered.
- `testFastingGapSatisfiedIncludesCandidate` — an insulin-bearing marker
  entry (via `setInsulinDose()`), then a 3-day fasting streak where the first
  fasting entry is ≥ 6h after the marker; assert `available` is `true` with
  the direction/dose matching a normal qualifying streak.
- `testFastingGapViolatedExcludesCandidate` — an insulin-bearing marker
  entry, then a fasting entry < 6h later (violates the gap, excluded from
  candidates entirely) followed by exactly 2 more qualifying fasting days (3
  calendar days total); since the violating day never becomes a candidate,
  only 2 real candidates remain — fewer than `REQUIRED_CONSECUTIVE_DAYS`;
  assert `available` is `false`.
- `testFastingAtBandHighBoundaryIsNotHigh` — 3 consecutive days at exactly
  `BAND_HIGH` (145 mg/dL); assert `available` is `false` (145 is not `>` 145).
- `testFastingJustAboveBandHighIsHigh` — 3 consecutive days at 146 mg/dL;
  assert `available` is `true` with the high-direction context message.
- `testFastingAtBandLowBoundaryIsNotLow` — 3 consecutive days at exactly
  `BAND_LOW` (95 mg/dL); assert `available` is `false`.
- `testFastingJustBelowBandLowIsLow` — 3 consecutive days at 94 mg/dL; assert
  `available` is `true` with the low-direction context message. Per user
  decision, name/comment this test as verifying *implemented* low-direction
  behavior, not a PRD-mandated rule (the PRD states only the high-glucose
  example, `prd.md:116`).
- `testDirectionFlipBreaksStreak` — a high day followed by 3 consecutive low
  days (or equivalent minimal sequence); assert the run correctly rebuilds
  from the flip point rather than merging counts across the direction change
  (distinct code path from the existing in-band-reset test, which uses "no
  direction" rather than "opposite direction").
- `testStreakUsesMostRecentThreeDaysNotFirstThree` — a streak of 4+
  consecutive qualifying days with *different* glycemia values across the
  streak (see Critical Implementation Details), such that the first-3 and
  last-3 windows would produce different `suggestedBaseDose` values; assert
  the value that only the most-recent-3 window produces.
- `testSuggestedValueClampsToLowerBound` — a profile with `baseDose` near the
  entity's floor (e.g. 1 or 2) and a qualifying low-direction streak large
  enough to push the raw result below 1; assert `suggestedBaseDose` is
  clamped to `1`, mirroring the existing upper-bound entity-range test.
- `testSameCalendarDayMultipleEntriesUsesFirstOnly` — one calendar day with
  two entries at different times and different glycemia values (an earlier
  qualifying value and a later value that would break the streak if used
  instead), plus 2 more single-entry qualifying days; assert `available` is
  `true`, proving `buildFastingCandidates()`'s first-entry-per-date dedup
  (L96-98) is what's driving the result — if the later same-day entry were
  used instead, the streak would break and the test would fail.

All new tests assert `result->available` plus `suggestedBaseDose`/`context`
where the scenario has a determinate numeric outcome, matching the file's
existing assertion depth (per user decision) — a bare `available` assertion
alone is not sufficient for the dedup test specifically, since a wrong-entry
bug could still produce `available === true` with the wrong dose.

#### 2. Base-dose suggestion source — reachability notes for `MIN_MAGNITUDE` and `STEP_CLAMP_MIN`

**File**: `src/Service/Suggestion/BaseDoseSuggestionService.php`

**Intent**: Document, at both the `MIN_MAGNITUDE` exclusion check and the
`STEP_CLAMP_MIN` clamp, that neither branch is meaningfully testable through
the public API under today's constants — so a future reader doesn't assume
either is covered, and so a future constant change (to
`BAND_HIGH`/`BAND_LOW`/`FACTOR`/`MIN_MAGNITUDE`/`STEP_CLAMP_MIN`, or to
`DiaryEntry`'s `Assert\Range` on `glycemiaMgDl`) prompts a re-check rather
than silent drift.

**Contract**: Two comments:
- Above line 66 (`if (abs($krokRaw) <= self::MIN_MAGNITUDE)`) stating the
  numeric reason (minimum qualifying excess is 26 mg/dL, giving
  `krokRaw = 0.52 > MIN_MAGNITUDE = 0.5`) and that no test currently drives
  this branch through `suggestFor()` as a result.
- Above line 64 (`$krokRaw = min(max($krokRaw, self::STEP_CLAMP_MIN), ...)`)
  stating that the low side of this clamp is unreachable via
  clinically-valid input: `DiaryEntry`'s `Assert\Range(min: 21, max: 2000)`
  on `glycemiaMgDl` caps the most negative average excess at -99 (all three
  days at the floor), giving `krokRaw = -1.98`, which never crosses -2.0 —
  and `round(-1.98)` already equals the clamped result, so no test could
  distinguish clamped from unclamped behavior without a value no patient
  could submit through the real form.

### Success Criteria:

#### Automated Verification:

- New and existing `BaseDoseSuggestionServiceTest` tests pass: `docker compose exec php vendor/bin/phpunit tests/Service/Suggestion/BaseDoseSuggestionServiceTest.php`
- Full suite stays green: `docker compose exec php vendor/bin/phpunit`
- Static analysis passes: `docker compose exec php vendor/bin/phpstan analyse`
- Code style is clean: `docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff`

#### Manual Verification:

- Read the `MIN_MAGNITUDE` and `STEP_CLAMP_MIN` comments and confirm each
  accurately states a reachability limitation, not an implied bug
- Confirm the new low-direction boundary test's name/comment clearly reads
  as implementation-verified behavior, not a PRD citation

---

## Phase 2: Hypoglycemia Boundary Test & Documentation Sync

### Overview

Add the missing insulin-adjusted exact-threshold test to
`HypoglycemiaWarningServiceTest.php`, and correct the wrong commit-count
figure in `context/foundation/test-plan.md`'s Risk Map for risk #2.

### Changes Required:

#### 1. Hypoglycemia warning test suite

**File**: `tests/Service/Warning/HypoglycemiaWarningServiceTest.php`

**Intent**: Close the one remaining gap — every existing boundary test uses
`insulinDose: null`, and every existing insulin-dose test lands comfortably
off a threshold (not exactly on one); no test proves the strict `<`
comparison (`HypoglycemiaWarningService.php:32`) holds when the *insulin-
adjusted* projection, not just the raw glycemia, lands exactly on a
threshold.

**Contract**: Add `testInsulinAdjustedProjectedGlycemiaExactlyAtThresholdYieldsNone`
— choose `glycemiaMgDl`, `insulinDose`, and an `ActivityIntensity` such that
`glycemiaMgDl - insulinDose * INSULIN_DROP_PER_UNIT_MGDL` equals that
intensity's threshold exactly (e.g. Medium: glycemia 200, insulinDose 2.0 →
projected 110 = `THRESHOLD_MEDIUM_MGDL`); assert `result->available` is
`false`, matching the existing exact-threshold tests' inline hand-arithmetic
comment style (e.g. `// 200 - 2.0 * 45 = 110, not < 110`).

#### 2. Test-plan hot-spot correction

**File**: `context/foundation/test-plan.md`

**Intent**: Correct risk #2's Risk Map evidence column, which currently
cites "hot-spot dir `src/Service` (18 commits/30d)" — research verified via
`git log --since="30 days ago" --oneline -- src/Service` that the real count
is 6 commits, only 3 of which touch Suggestion/Warning code (the other 3
touch unrelated `Chart`/`History`/`Editability`/`Export` services), all
same-day feature-plus-fix commits, not a pattern of recurring bugs.

**Contract**: In the `## 2. Risk Map` table, row `#2`, replace "hot-spot dir
`src/Service` (18 commits/30d)" with the verified figure ("hot-spot dir
`src/Service` (6 commits/30d, 3 relevant to Suggestion/Warning)"). No other
column or row changes.

### Success Criteria:

#### Automated Verification:

- New and existing `HypoglycemiaWarningServiceTest` tests pass: `docker compose exec php vendor/bin/phpunit tests/Service/Warning/HypoglycemiaWarningServiceTest.php`
- Full suite stays green: `docker compose exec php vendor/bin/phpunit`
- Static analysis passes: `docker compose exec php vendor/bin/phpstan analyse`

#### Manual Verification:

- Re-run `git log --since="30 days ago" --oneline -- src/Service` at
  implementation time and confirm the corrected figure in
  `context/foundation/test-plan.md` still matches (the 30-day window moves)
- Confirm the test-plan.md diff touches only the risk #2 evidence cell, no
  other Risk Map content

---

## Testing Strategy

### Unit Tests:

- All new tests are unit-level, matching the file's existing layer
  (`KernelTestCase` for `BaseDoseSuggestionServiceTest` — hits the DB via
  repositories; plain `TestCase` for `HypoglycemiaWarningServiceTest` — pure
  function, no I/O).
- Every new numeric assertion must be hand-derivable from the stated
  constants/formula (oracle-problem discipline already followed by the
  existing suites) — no assertion should be a re-run of the service's own
  live output copied into the test.

### Integration Tests:

- None — this risk's cheapest useful layer is unit (confirmed by research);
  no controller or HTTP surface is touched by either service.

### Manual Testing Steps:

1. Run the full suite (`docker compose exec php vendor/bin/phpunit`) and
   confirm all new and existing tests pass.
2. Review the `MIN_MAGNITUDE` comment and the low-direction test's
   annotation for accuracy per the Manual Verification items above.
3. Confirm the `test-plan.md` correction is a single-cell edit with no
   collateral changes.

## Performance Considerations

None — no new I/O patterns, no changed query shapes; new tests reuse the
existing per-test create/cleanup pattern.

## Migration Notes

Not applicable — no schema or data changes.

## References

- Related research: `context/changes/testing-recommendation-algorithm-edge-cases/research.md`
- Reference pattern (already fully covered, no changes needed): `tests/Service/Suggestion/InsulinWwRatioSuggestionServiceTest.php`
- `src/Service/Suggestion/BaseDoseSuggestionService.php:1-165`
- `src/Service/Warning/HypoglycemiaWarningService.php:1-38`
- `context/foundation/test-plan.md` §2 (Risk Map), §6.1 (unit-test cookbook pattern)
- `context/foundation/prd.md:110-118` (Business Logic — base-dose PRD text, high-glucose example only)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Base-Dose Boundary & Exclusion Coverage

#### Automated

- [x] 1.1 New and existing BaseDoseSuggestionServiceTest tests pass — c125400
- [x] 1.2 Full suite stays green — c125400
- [x] 1.3 Static analysis passes — c125400
- [x] 1.4 Code style is clean — c125400

#### Manual

- [x] 1.5 MIN_MAGNITUDE and STEP_CLAMP_MIN comments reviewed for accuracy — c125400
- [x] 1.6 Low-direction boundary test annotation reviewed for PRD-honesty — c125400

### Phase 2: Hypoglycemia Boundary Test & Documentation Sync

#### Automated

- [x] 2.1 New and existing HypoglycemiaWarningServiceTest tests pass — e630bdb
- [x] 2.2 Full suite stays green — e630bdb
- [x] 2.3 Static analysis passes — e630bdb

#### Manual

- [x] 2.4 Corrected commit-count figure re-verified against current `git log` output — e630bdb
- [x] 2.5 test-plan.md diff confirmed single-cell, no collateral changes — e630bdb
