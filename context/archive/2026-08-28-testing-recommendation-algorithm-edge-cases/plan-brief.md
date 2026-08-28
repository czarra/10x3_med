# Recommendation-Algorithm Edge-Case Test Coverage — Plan Brief

> Full plan: `context/changes/testing-recommendation-algorithm-edge-cases/plan.md`
> Research: `context/changes/testing-recommendation-algorithm-edge-cases/research.md`

## What & Why

Close the boundary/edge-case unit-test gaps on `BaseDoseSuggestionService`
and `HypoglycemiaWarningService` — rollout Phase 2 of the project's test
plan, addressing risk #2: recommendation algorithms producing an incorrect
medical suggestion on boundary or incomplete data, which a patient could act
on. `InsulinWwRatioSuggestionService` already has complete coverage and is
out of scope.

## Starting Point

All three suggestion/warning services already have unit-test suites written
alongside their original implementation. Research confirmed the ratio
service has no gaps, but `BaseDoseSuggestionServiceTest.php` (10 tests) is
missing 11 reachable scenarios — most notably, the `FASTING_GAP_HOURS`
branch has zero real coverage because every existing fixture leaves
`ww`/`insulinDose` unset — and `HypoglycemiaWarningServiceTest.php` (12
tests) is missing one insulin-adjusted exact-boundary case. Plan review also
surfaced a second untestable branch alongside the already-known
`MIN_MAGNITUDE` one: `STEP_CLAMP_MIN`'s low side can't be driven past its
boundary using any glycemia value a patient could submit through the real
form.

## Desired End State

Both test files exercise every branch identified in the research doc's gap
tables: empty history, the real (non-vacuous) fasting-gap check, both
`BAND_HIGH`/`BAND_LOW` exact boundaries, a direction-flip streak reset, a
streak longer than 3 days, both symmetric step/dose clamps, same-day dedup,
and the insulin-adjusted exact-threshold case. `test-plan.md`'s risk #2
evidence cites the verified commit count instead of the wrong one.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| `MIN_MAGNITUDE` branch (likely unreachable under current constants) | Document with a source comment, write no test for it | A test that can't be driven through the public API would either be untestable or would test private internals, breaking the suite's black-box pattern | Plan |
| `STEP_CLAMP_MIN` branch (found unreachable via valid input during plan review) | Document with a source comment alongside `MIN_MAGNITUDE`, write no test for it | `DiaryEntry`'s own `Assert\Range(min:21)` floor caps achievable negative excess so tightly that `round()` already matches the clamped output — a realistic-value test would pass with or without the clamp | Plan Review |
| Gap scope | Cover all reachable identified gaps in one pass (11 base-dose + 1 hypoglycemia) | Matches the test-plan's Phase 2 goal fully; each gap is a cheap unit test once the fixture pattern exists | Plan |
| `FASTING_GAP_HOURS` fixture approach | Two focused tests: gap satisfied and gap violated | Proves both branches of the real condition directly, matching the suite's one-scenario-per-test convention | Plan |
| Base-dose low-direction PRD gap | Test it, but document as implementation-verified behavior, not PRD-mandated | Keeps oracle-problem discipline honest without blocking on a PRD update | Plan |
| Wrong "18 commits/30d" stat | Correct it in this change | One-line fix; prevents a wrong figure from propagating to later rollout phases | Plan |
| New-test assertion depth | Match existing style — assert `available` plus the numeric outcome | Consistent with every existing test; a bare `available` check would miss a wrong-value bug in the dedup test specifically | Plan |

## Scope

**In scope:**
- 11 new test methods across `BaseDoseSuggestionServiceTest.php`
- 1 new test method in `HypoglycemiaWarningServiceTest.php`
- 2 source comments in `BaseDoseSuggestionService.php` (`MIN_MAGNITUDE` and `STEP_CLAMP_MIN` reachability notes)
- 1 factual correction in `context/foundation/test-plan.md`'s Risk Map

**Out of scope:**
- `InsulinWwRatioSuggestionService` / its test file (already fully covered)
- A test for the `MIN_MAGNITUDE` branch (not reachable through the public API)
- A test for the `STEP_CLAMP_MIN` low-side branch (found unreachable via clinically-valid input during plan review)
- Opening a separate PRD-update change for the base-dose low-direction gap
- Any change to `test-plan.md`'s Phased Rollout status table or `roadmap.md`
  (no roadmap entry exists for this change)
- Integration/functional tests (unit is the confirmed cheapest useful layer)

## Architecture / Approach

No new infrastructure. Every new test reuses the target file's existing
fixture helpers (`createUser`/`createProfile`/`createFastingEntry`/
`createFastingEntryAt` for base-dose; the local `createEntry` helper for
hypoglycemia) and assertion style. The only non-test files touched are two
short source comments and a one-cell documentation correction.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Base-dose boundary & exclusion coverage | 11 new test methods + `MIN_MAGNITUDE`/`STEP_CLAMP_MIN` source comments | Fixture construction for the fasting-gap branch is the trickiest part — needs a marker entry with `setInsulinDose()` to make the gap check non-vacuous |
| 2. Hypoglycemia boundary test & doc sync | 1 new insulin-adjusted boundary test + `test-plan.md` stat correction | The 30-day commit-count window moves — the corrected figure should be re-verified against `git log` at implementation time, not assumed frozen |

**Prerequisites:** None beyond the existing Docker/PHP test environment
(`docker compose exec php vendor/bin/phpunit`).
**Estimated effort:** Small — one session, two phases, 12 new test methods
plus a couple of short documentation/comment edits.

## Open Risks & Assumptions

- The exact numeric fixture values proposed for the remaining streak/boundary
  tests are illustrative in the plan; the implementer should verify the
  arithmetic against the live constants before committing to specific
  numbers.
- The 30-day commit-count window used for the `test-plan.md` correction will
  have moved by the time Phase 2 lands — re-verify with `git log` rather than
  copying the plan's figure verbatim.
- `STEP_CLAMP_MIN`'s low side and `MIN_MAGNITUDE` both stay formally
  untested by design (documented via source comments) — if a future change
  narrows the gap between `DiaryEntry`'s `Assert\Range` floor and
  `TARGET_GLYCEMIA`, or changes `BAND_HIGH`/`BAND_LOW`/`FACTOR`, both
  comments should be re-checked for continued accuracy.

## Success Criteria (Summary)

- `docker compose exec php vendor/bin/phpunit` passes with all new tests included
- `phpstan analyse` and `php-cs-fixer fix --dry-run` stay clean
- Every gap itemized in `research.md`'s two gap tables has a corresponding new test or a documented reason it's intentionally untested (`MIN_MAGNITUDE`, `STEP_CLAMP_MIN`)
