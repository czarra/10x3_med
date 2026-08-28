---
date: 2026-08-28T14:59:43+02:00
researcher: Claude (10x-research)
git_commit: 2dd6b7edb469586f19af0f65cb1496501d9ab73e
branch: main
repository: symfony (dia-guide)
topic: "Rollout Phase 2 grounding — recommendation-algorithm edge-case coverage (risk #2)"
tags: [research, codebase, test-plan, suggestion-services, insulin-ww-ratio, base-dose, hypoglycemia-warning]
status: complete
last_updated: 2026-08-28
last_updated_by: Claude (10x-research)
---

# Research: Grounding rollout Phase 2 — recommendation-algorithm edge-case coverage (risk #2)

**Date**: 2026-08-28T14:59:43+02:00
**Researcher**: Claude (10x-research)
**Git Commit**: 2dd6b7edb469586f19af0f65cb1496501d9ab73e
**Branch**: main
**Repository**: symfony (dia-guide)

## Research Question

Ground `context/foundation/test-plan.md` rollout Phase 2 (risk #2 — recommendation
algorithms producing an incorrect medical suggestion on boundary/incomplete data)
in real code: locate the actual failure path, quote the relevant PRD/plan/source
lines, verify or correct the risk-response guidance (the "prove a minimum, PRD is
the oracle, avoid the oracle problem" claims), locate existing tests, identify the
cheapest useful test layer, and flag any misleading hot-spot evidence.

## Summary

**The risk-response guidance's core claims hold, with one important refinement,
and the risk is materially less "green-field" than the test-plan implies.**

1. **All three algorithms already have unit-test suites** —
   `InsulinWwRatioSuggestionServiceTest.php` (9 tests), `BaseDoseSuggestionServiceTest.php`
   (10 tests), `HypoglycemiaWarningServiceTest.php` (12 tests) — 31 tests total,
   written alongside the original implementation (`git log` shows tests landed in
   the same commits as the services, one day before this rollout phase was even
   opened). The minimum-data and direction-rule proofs the response guidance asks
   for **already exist** for the ratio service specifically
   (`testFewerThanThreePairsYieldsNone`, `testRisingDeltasSuggestHigherRatio`,
   `testFallingDeltasClampToMinStep`). Phase 2 is a **gap-filling** exercise, not
   a from-scratch build.
2. **The oracle problem guidance needs one correction**: strictly, the PRD gives
   only a minimum-count (3) and a *qualitative* direction rule — it never states
   exact mg/dL thresholds; it flags its own numbers as examples ("np."). The real
   numeric oracle for constants like `RATIO_THRESHOLD_MGDL=45` is the archived
   implementation plan's product-owner-approved, hand-worked examples
   (`context/archive/2026-08-25-insulin-ww-ratio-suggestion/plan.md`) — for the
   ratio service, that number is further backed by cited clinical literature
   (ELSA-Brasil study) in `context/archive/.../research.md`. Existing tests
   correctly assert against these worked examples, not against re-run service
   math — so no oracle-problem violation was found in the *existing* suite. But
   the base-dose and hypoglycemia-warning constants have **no PRD text and no
   FR** behind them at all (see §"PRD vs. implementation" below) — a new test
   must not present those numbers as "the PRD rule."
3. **The real, actionable gap is boundary coverage on `BaseDoseSuggestionService`**
   and one boundary gap on `HypoglycemiaWarningService` — not the ratio service,
   which already has thorough boundary/minimum/direction/exclusion coverage. See
   §"Test coverage gaps" for the itemized list.
4. **The hot-spot evidence used to justify risk #2's likelihood is wrong and
   partially misleading.** The test plan cites "src/Service (18 commits/30d)"; the
   actual count is **6**, and only 3 of those 6 touch `Suggestion`/`Warning` at
   all — the other 3 touch unrelated services (`Chart`, `History`, `Editability`,
   `Export`). The 3 relevant commits are same-day `feat` + `fix` (impl-review
   correction) commits that shipped tests alongside the code, not a pattern of
   recurring bugs. This doesn't mean risk #2 is unfounded (PRD/interview evidence
   for it stands on its own), but the churn-based likelihood signal specifically
   should be treated as weak, not "18 commits of instability."
5. **Cheapest useful layer remains unit** (confirmed) — `KernelTestCase` for the
   two services that hit the DB via repositories (`InsulinWwRatioSuggestionService`,
   `BaseDoseSuggestionService`), plain `TestCase` for `HypoglycemiaWarningService`
   (pure function, no I/O) — matching the pattern already used by all three
   existing test files.

## Detailed Findings

### 1. PRD grounding — what's actually mandated vs. example vs. undocumented

PRD file: `context/foundation/prd.md`.

**Minimum-data requirement (PRD-mandated, class "a")**:
- Ratio: "Algorytm wymaga minimum 3 kompletnych par wpisów posiłkowych... w celu
  dokonania sugestii." (`prd.md:62`, US-01 Acceptance Criteria); "po zebraniu
  minimum 3 posiłków" (`prd.md:91`, FR-009).
- Base-dose: "3 dni z rzędu" (`prd.md:116`, Business Logic §2) — this is the
  day-count, stated as part of an "np." example, but "3" itself is asserted, not
  hedged, in `REQUIRED_CONSECUTIVE_DAYS = 3`.
- `AFTER_MEAL_TARGET_MINUTES = 120` matches "2 godziny po nim" / "po 2h"
  (`prd.md:115,62`) — also class "a".

**Direction rule (PRD-mandated, qualitative only, class "a")**:
- Ratio: "Jeżeli... różnica ta przekracza założony bezpieczny próg wzrostu...
  system sugeruje zwiększenie przelicznika. W przypadku spadu cukru sugerowane
  jest jego obniżenie." (`prd.md:115`) — rise → raise ratio, fall → lower it.
  Confirmed correct in `InsulinWwRatioSuggestionService.php:80`
  (`'rise' === $direction ? $currentRatio + $krok : $currentRatio - $krok`).
- Base-dose: PRD states only the **high-side** example ("3 dni z rzędu na czczo
  cukier przekracza 130 mg/dL" → "sygnalizuje potrzebę rewizji dawki dobowej",
  `prd.md:116`). It never states the symmetric low-fasting-glucose case in text.
  The implementation's `BAND_LOW`/low-direction branch is a reasonable but
  **PRD-unstated extrapolation** (a gap to note, not a bug).

**Numeric thresholds: PRD gives only illustrative examples, not a spec.**
`prd.md:115`: "np. glykemia wzrosła o więcej niż 50 mg/dL"; `prd.md:116`: "np...
cukier przekracza 130 mg/dL" — both explicitly marked "np." (e.g.). No PRD text
anywhere states step sizes, clamp ranges, band widths, a fasting-gap-hours
definition, or any hypoglycemia-warning formula at all.

**Constant-by-constant provenance** (full detail in the PRD-grounding subagent's
report; summarized here):

| Service | Constant | PRD text? | Documented elsewhere? |
|---|---|---|---|
| Ratio | `REQUIRED_PAIRS=3`, `AFTER_MEAL_TARGET_MINUTES=120` | **Yes** (a) | — |
| Ratio | `RATIO_THRESHOLD_MGDL=45` | No — PRD says "np. 50" (b) | Yes — `archive/2026-08-25-.../plan.md:278,285` + `research.md:281-330` cites ELSA-Brasil median postprandial excursion (45 mg/dL) as the empirical anchor for deliberately deviating from the PRD's own example |
| Ratio | `RATIO_MIN_STEP/MAX_STEP` (0.05/0.3), `RATIO_STEP_ROUNDING`, `SuggestionScaling::FACTOR=0.02`, majority-of-2-of-3 rule, final clamp `[0.1,10.0]` | No (b) | Yes — plan.md worked examples (plan.md:278-294), no PRD backing |
| Base-dose | `REQUIRED_CONSECUTIVE_DAYS=3` | **Yes** (a) | — |
| Base-dose | `FASTING_GAP_HOURS=6`, `TARGET_GLYCEMIA=120`, `BAND_LOW=95`, `BAND_HIGH=145` | **No** (c) | plan.md states them but with **no cited rationale** — `research.md:360-362` explicitly says "no equivalent delta literature was reviewed" for these. **Weakest-grounded constants in the codebase.** |
| Base-dose | `STEP_CLAMP_MIN/MAX`, `MIN_MAGNITUDE=0.5`, clamp `[1,35]`, whole-unit dose | No (b) | plan.md, product-owner planning-session decisions |
| Hypoglycemia | `THRESHOLD_LIGHT/MEDIUM/STRONG_MGDL` (90/110/140), `INSULIN_DROP_PER_UNIT_MGDL=45` | **No** (c) | `archive/2026-08-26-activity-hypoglycemia-warning/plan.md:87-95` states outright: "PRD... nie definiuje algorytmu dla S-04 — próg i formuła poniżej to decyzja podjęta wspólnie z użytkownikiem podczas tej sesji planowania" (an un-cited planning-session decision — **no FR, no PRD text, no clinical citation at all**, unlike the ratio's analogous 45 mg/dL) |

**Important nuance**: base-dose has **no FR/AC in the PRD at all** — it's PRD
narrative-only (Business Logic §2), pulled into scope by an explicit product-owner
decision documented in `archive/2026-08-25-.../plan.md:9,13,16` ("PRD narrative
only, no FR/AC — implemented anyway per product owner"). A test description citing
"FR-009" or "US-01" as the base-dose service's spec would be citing the wrong
requirement — no FR covers it.

**FR-005/FR-006 confirm exclusion of incomplete entries is PRD-mandated**:
- "Wpisy bez podanego WW... nie wpływają na kalkulator przelicznika posiłkowego."
  (`prd.md:79-80`)
- "Wpisy bez podanej dawki insuliny... nie są jednak brane pod uwagę przy
  automatycznym korygowaniu przelicznika posiłkowego." (`prd.md:81-82`)

These map directly to `InsulinWwRatioSuggestionService.php:99`
(`null === $meal->getWw() || $meal->getWw() <= 0.0 || null === $meal->getInsulinDose()`)
— the `<= 0.0` (zero-WW) guard specifically is **not** stated in the PRD; it's a
defensible division-by-zero safety guard (already tested:
`testZeroWwMealIsExcludedFromPairing`).

**FR-010 confirms the hypoglycemia warning must stay qualitative**: "system nie
sugeruje konkretnej redukcji dawki (np. o 20%), a jedynie informuje o ryzyku hipo"
(`prd.md:94`). Verified: `HypoglycemiaWarningResult` (`src/Service/Warning/HypoglycemiaWarningResult.php`)
carries only `available: bool` and a fixed generic `message: string`
(`BASE_MESSAGE` in `HypoglycemiaWarningService.php:15`) — no numeric dose
recommendation is ever surfaced to the patient. This PRD rule is upheld.

### 2. Actual algorithm code (all three services, `src/Service/`)

**`InsulinWwRatioSuggestionService.php`** (already fully covered by existing tests,
see §3):
- `suggestFor()`: pulls `RatioAdjustmentHistory` cutoff, builds meal pairs
  (`buildMealPairs`, L95-131), requires ≥3 pairs (`REQUIRED_PAIRS`, L33), takes
  the **last 3** pairs (L37), classifies each as rising/falling by
  `RATIO_THRESHOLD_MGDL=45` (L47-51), requires ≥2-of-3 agreement in one direction
  (L54-62), computes a WW-normalized average excess → scaled step → clamped
  →rounded (L64-77), applies to `currentRatio`, clamps to `[0.1, 10.0]` (L79-81).
- `buildMealPairs()` excludes any "before" meal missing WW, WW≤0, or missing
  insulin dose (L99), and pairs it with the closest reading in a
  `120min ± 30min` window (L103-123) — an unmatched meal is silently dropped
  (no pair formed), not counted.

**`BaseDoseSuggestionService.php`** (has real coverage gaps, see §3):
- `suggestFor()`: uses the **full unfiltered history** (not cutoff-filtered) to
  build fasting candidates (L34, deliberately — comment explains fasting-day
  classification depends on the whole timeline), then restricts the *scan
  window* by cutoff (L41-51). Finds the most recent run of 3 consecutive
  qualifying calendar days (`findMostRecentRun`, L122-164), computes average
  excess over `TARGET_GLYCEMIA=120` → scaled step, clamped to `[-2.0, 2.0]`
  (L64), excluded if `abs(krokRaw) <= MIN_MAGNITUDE=0.5` (L66-68), rounded to
  whole units, applied to `currentBaseDose`, clamped to `[1, 35]` (L73).
- `buildFastingCandidates()` (L87-115): only the **first** entry per calendar
  date becomes a candidate (L96-98, `!isset($seenDates[$dateStr])`); the fasting
  gap check (`>= FASTING_GAP_HOURS*3600` since the last insulin/WW-bearing entry,
  L101-102) is "vacuously satisfied" when no prior insulin-bearing entry exists
  at all.
- `findMostRecentRun()` (L122-164): normalizes cursor/maxDate to midnight to
  avoid time-of-day off-by-one (L131-132); a day with no candidate, or one that
  flips direction relative to the current run, resets the run (L146-151); the
  run keeps sliding forward as long as the streak continues, so `bestRun` always
  reflects the **most recent** 3 qualifying days of a longer streak (L155-157).

**`HypoglycemiaWarningService.php`** (has one real coverage gap, see §3):
- `evaluate()`: returns `none()` if no `ActivityIntensity` is set (L20-22);
  otherwise picks a threshold by intensity (Light=90/Medium=110/Strong=140,
  L24-28); computes `projectedGlycemia = glycemia - (insulinDose ?? 0.0) * 45`
  (L30); warns iff `projectedGlycemia < threshold` (strict `<`, L32) — equality
  at the threshold does **not** warn.

### 3. Existing test coverage — what's proven, what's not

**`InsulinWwRatioSuggestionServiceTest.php`** — **9 tests, comprehensive.**
Already proves everything the response guidance asks for: fewer-than-3-pairs
→ none (`testFewerThanThreePairsYieldsNone`), both directions with correct sign
(`testRisingDeltasSuggestHigherRatio`, `testFallingDeltasClampToMinStep`), min/max
step clamping, entity-range clamping, mixed-direction → none, unmatched-meal
exclusion, zero-WW exclusion, and the re-trigger cutoff. **No gaps identified.**

**`BaseDoseSuggestionServiceTest.php`** — 10 tests, but with real gaps:

| Gap | Why it matters |
|---|---|
| No test for empty history (`$entries === []`, L35-37) | Trivial to add; currently 0% covered |
| **`FASTING_GAP_HOURS` computed branch (L102) has zero coverage** — every existing test leaves `ww`/`insulinDose` unset, so `$lastInsulinBearingAt` stays `null` for the whole suite and only the "vacuously satisfied" branch (L101) ever runs. `testFastingGapIsVacuouslySatisfiedWhenNoPriorEntryExists` is misleadingly named — every other test exercises the same vacuous path, just unlabeled. | This is the single biggest gap: the actual "is this really a fasting reading" gate is entirely unverified. |
| No boundary test at `BAND_HIGH=145` (145 exactly → not high; 146 → high) or `BAND_LOW=95` (95 exactly → not low; 94 → low) | Off-by-one risk exactly matches the risk register's stated concern ("boundary... data") |
| No direction-flip-breaks-streak test (existing "breaks streak" test uses an in-band value, not a high→low flip) | Distinct code path (L149-151) from the tested in-band-reset path |
| No test for a streak **longer** than 3 days (sliding-window semantics — `bestRun` should reflect the most recent 3, not the first 3) | Untested "most recent run" behavior |
| No `STEP_CLAMP_MIN` (-2.0) boundary test (only the max clamp, +2.0, is tested) | Asymmetric coverage |
| No lower-bound (`newBaseDose` clamp to 1) test (only the upper bound, 35, is tested) | Asymmetric coverage |
| No same-calendar-day-multiple-entries dedup test | `buildFastingCandidates` only keeps the first entry per date (L96-98) — unverified |
| **Design-integrity flag, not a test gap**: given `BAND_HIGH/BAND_LOW = TARGET_GLYCEMIA ± 25` and `25 * SuggestionScaling::FACTOR(0.02) = 0.5 = MIN_MAGNITUDE`, but classification is strict `>`/`<` (so the minimum qualifying per-day excess is 26, not 25), the `MIN_MAGNITUDE` exclusion branch (L66-68) appears **unreachable** through the public API under today's constants. Worth flagging to the developer before writing a test that can never pass through legitimate inputs. | Possible latent design mismatch between `BAND_*` and `MIN_MAGNITUDE`/`FACTOR` |

**`HypoglycemiaWarningServiceTest.php`** — 12 tests, thorough (exact-threshold
boundary tested for all 3 intensities, both with and without insulin dose
shifting the result). One gap:

| Gap | Why it matters |
|---|---|
| No test where an insulin-adjusted `projectedGlycemia` lands **exactly** on a threshold (existing insulin-dose tests don't land exactly on a boundary; existing boundary tests use `insulinDose=null`) | Combined-boundary case is untested |

**Oracle-problem check**: no violations found in any existing test. Numeric
assertions in the ratio and base-dose suites are hand-derivable from the stated
constants/formula and match the archived plan's independently-worked examples
(e.g. `plan.md:291-294,315-318`), not re-runs of the service's own live output.
The hypoglycemia suite's insulin-adjusted assertions include inline
hand-arithmetic comments (`150 - 1.5*45 = 82.5 < 110`) confirming the same.

### 4. Hot-spot evidence verification

Test-plan cites `src/Service (18 commits/30d)` as risk #2's likelihood evidence.
**This figure is wrong.** `git log --since="30 days ago" --oneline -- src/Service`
(window 2026-07-29 → 2026-08-28) returns **6** commits total, not 18, all dated
2026-08-26/27:

| Commit | Path(s) | Category |
|---|---|---|
| `86db03f` | `src/Service/Suggestion/*` | Suggestion/Warning |
| `04004b8` | `src/Service/Suggestion/*` | Suggestion/Warning |
| `2f291b5` | `src/Service/Warning/*` | Suggestion/Warning |
| `2504716` | `src/Service/Chart/*`, `src/Service/History/*` | Unrelated |
| `e410b10` | `src/Service/Editability/*` | Unrelated |
| `12f89b0` | `src/Service/Export/*` | Unrelated |

Only 3 of 6 (50%) touch the services relevant to risk #2. Of those 3:
`86db03f` (`feat: suggestion services, dashboard, tests (p3-6)`), `04004b8`
(`fix: address impl-review findings, stamp impl_reviewed`), `2f291b5`
(`feat: add hypoglycemia warning domain service (p1)`) — all same-day, all
added/modified their matching test files in the same commit (confirmed:
`86db03f`'s message notes "24 new tests"). This is normal feature construction
with a same-cycle impl-review fix, not a pattern of recurring post-release bugs.

**Verdict**: the churn-based likelihood signal for risk #2 is weaker than the
test plan states — both because the raw count is wrong (6, not 18) and because
half the real churn is unrelated to the suggestion/warning logic. The risk
itself still stands on its PRD/interview evidence (unaffected by this
correction), but `/10x-test-plan --refresh` should correct or drop the "18
commits/30d" figure the next time it runs.

## Code References

- `src/Service/Suggestion/InsulinWwRatioSuggestionService.php:1-132` — ratio algorithm, fully tested
- `src/Service/Suggestion/BaseDoseSuggestionService.php:1-165` — base-dose algorithm, gaps in boundary/gap-hours/streak coverage
- `src/Service/Warning/HypoglycemiaWarningService.php:1-38` — hypoglycemia warning, one boundary gap
- `src/Service/Suggestion/SuggestionScaling.php:7` — shared `FACTOR = 0.02` used by both suggestion services
- `tests/Service/Suggestion/InsulinWwRatioSuggestionServiceTest.php:1-293` — reference pattern, no gaps
- `tests/Service/Suggestion/BaseDoseSuggestionServiceTest.php:1-308` — 10 tests, gaps listed in §3
- `tests/Service/Warning/HypoglycemiaWarningServiceTest.php:1-148` — 12 tests, one gap in §3
- `context/foundation/prd.md:55-64` (US-01), `:79-82` (FR-005/006), `:91-96` (FR-009/010/011), `:110-118` (Business Logic) — PRD source text
- `context/archive/2026-08-25-insulin-ww-ratio-suggestion/plan.md:278-330` — ratio constants + worked examples + ELSA-Brasil citation
- `context/archive/2026-08-25-insulin-ww-ratio-suggestion/plan.md:302-318` — base-dose constants + worked examples (no citation)
- `context/archive/2026-08-26-activity-hypoglycemia-warning/plan.md:87-95` — explicit statement that PRD does not define the hypoglycemia algorithm

## Architecture Insights

- All three suggestion/warning services follow the same shape: pure calculation
  from `DiaryEntry` history + a `*Result` value object with `available`/`none()`
  factory pattern — no framework coupling beyond repository injection, which is
  why `KernelTestCase` (DB-backed) vs. plain `TestCase` (pure function, no I/O
  for `HypoglycemiaWarningService`) split cleanly, and matches the test-plan's
  §6.1 cookbook pattern.
- Both suggestion services share `SuggestionScaling::FACTOR` deliberately, per
  the plan ("so the two algorithms' proportional-step math can't silently drift
  apart") — a change to `FACTOR` affects both; any future test of one in
  isolation should be aware the constant is shared.
- The re-trigger cutoff (only entries/days after the last accepted suggestion
  count) is implemented via append-only `RatioAdjustmentHistory` /
  `BaseDoseAdjustmentHistory` entities — an entirely plan.md-only design
  decision, not mentioned anywhere in the PRD.
- `BaseDoseSuggestionService` deliberately uses the **full** unfiltered entry
  history for fasting-day *classification* (independent of cutoff) but restricts
  the *scan window* by cutoff — an easy detail to get backwards when writing new
  tests; see `BaseDoseSuggestionService.php:32-34` comment.

## Historical Context (from prior changes)

- `context/archive/2026-08-25-insulin-ww-ratio-suggestion/` — the plan and
  research that originally implemented both suggestion services; its
  `research.md` is the authoritative source for why `RATIO_THRESHOLD_MGDL=45`
  deviates from the PRD's own "np. 50" example (cited ELSA-Brasil data), and
  explicitly notes base-dose's constants received no equivalent literature
  review.
- `context/archive/2026-08-26-activity-hypoglycemia-warning/` — the plan that
  implemented `HypoglycemiaWarningService`; states outright that the PRD defines
  no algorithm for this feature and that all thresholds are an un-cited
  planning-session decision.
- `context/archive/2026-08-28-testing-authorization-access-boundary/` — Phase 1
  of this same test-plan rollout (risk #1/#5), already closed; establishes the
  `KernelTestCase` + `uniqid()`-suffixed-email + raw-`DELETE` cleanup pattern
  that both `InsulinWwRatioSuggestionServiceTest.php` and
  `BaseDoseSuggestionServiceTest.php` already follow.

## Related Research

- `context/foundation/test-plan.md` §2 (Risk Map), §3 (Phased Rollout) — the
  document this research grounds.
- `context/archive/2026-08-25-insulin-ww-ratio-suggestion/research.md` — prior
  research explicitly covering the ratio/base-dose threshold-value question this
  Phase 2 risk also raises; largely superseded for the ratio service (already
  resolved and implemented) but still the best source for base-dose's weak
  numeric grounding.

## Open Questions

1. **Should `/10x-plan` for this phase scope in the `MIN_MAGNITUDE` reachability
   flag** (§3, `BaseDoseSuggestionService`) as a design question to raise with
   the product owner, or leave it purely as a code comment/note? It's a
   potential latent design mismatch, not a confirmed bug — no test can currently
   drive that branch through the public API given today's constants.
2. **Should the base-dose low-direction PRD-symmetry gap** (PRD only states the
   high-fasting-glucose example; the low-side rule is implementation-only) be
   flagged for a PRD update, or is the implemented symmetry accepted as an
   already-approved product-owner extrapolation (per the archived plan)? Not
   blocking for Phase 2 test-writing either way.
3. Should `/10x-test-plan --refresh` be run to correct the "18 commits/30d"
   hot-spot figure for `src/Service` before it propagates to future rollout
   phases (e.g. if a Phase 5 ever cites the same stat)?
