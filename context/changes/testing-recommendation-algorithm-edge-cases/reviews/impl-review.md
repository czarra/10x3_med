<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Recommendation-Algorithm Edge-Case Test Coverage

- **Plan**: context/changes/testing-recommendation-algorithm-edge-cases/plan.md
- **Scope**: Phase 1 and Phase 2 (full plan)
- **Date**: 2026-08-28
- **Verdict**: APPROVED
- **Findings**: 0 critical, 2 warnings, 1 observation
- **Triage**: complete — F1 fixed, F2 accepted, F3 recorded as lesson (lesson-only, not fixed)

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | WARNING |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Findings

### F1 — InsulinWwRatioSuggestionService.php rename not documented in plan

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Scope Discipline
- **Location**: src/Service/Suggestion/InsulinWwRatioSuggestionService.php
- **Detail**: Mid-implementation, the user asked to rename Polish variable names (`$krokRaw`, `$krok`, `$nadwyzka(s)`) to English and explicitly chose to expand that to `InsulinWwRatioSuggestionService.php` too — even though `plan.md`'s "What We're NOT Doing" section states this file is "already fully covered, not touching." Verified as a pure identifier rename (`$nadwyzka`→`$excess`, `$krokRaw`→`$stepRaw`, `$krokClamped`→`$stepClamped`, `$krok`→`$step`); formulas, control flow, and clamp bounds untouched — no behavior change. The decision itself was made transparently in-session via AskUserQuestion, but `plan.md` was never updated to record the exception, so a future reader of the plan alone would see "not touching" and be surprised this file changed.
- **Fix**: Add a one-line addendum to `plan.md`'s "What We're NOT Doing" section noting the user-approved naming-only exception for this file.
- **Decision**: FIXED — addendum added to plan.md's "What We're NOT Doing" section.

### F2 — test-plan.md §3 Phased Rollout table touched despite plan's stated exclusion

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Scope Discipline
- **Location**: context/foundation/test-plan.md (§3 Phased Rollout, row #2)
- **Detail**: The Phase 1 commit (`c125400`) also carries a change to row #2's Status ("not started"→"change opened") and Change folder ("—"→ this change's path). `plan.md`'s "What We're NOT Doing" section explicitly says: "Not modifying `context/foundation/test-plan.md`'s Phased Rollout status table (§3) ... the rollout status field is owned by the `/10x-test-plan` orchestration, not this plan." This edit predates this implementation session (made by `/10x-new` when the change folder was opened) and was already dirty in the working tree before Phase 1 began; it was bundled into the Phase 1 commit with the user's explicit, in-session approval during the dirty-path prompt. Content is accurate (the change genuinely was opened) and not something Phase 1's own contract added — this is a process note, not a defect.
- **Fix**: No action needed — content is correct and the bundling was explicitly user-approved. Noted here only so the plan's stated exclusion and the actual commit history aren't out of sync for a future reader.
- **Decision**: ACCEPTED — content correct, bundling explicitly user-approved during Phase 1's dirty-path prompt.

### F3 — Oracle-problem caveat inherent to base-dose/hypoglycemia constants

- **Severity**: ℹ️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: tests/Service/Suggestion/BaseDoseSuggestionServiceTest.php (all 11 new tests)
- **Detail**: All new numeric assertions are hand-derived from the service's own constants/formula (`SuggestionScaling::FACTOR=0.02`, `BAND_HIGH/LOW`, clamps) rather than an independently-specified PRD oracle, since the PRD only states qualitative rules for base-dose/hypoglycemia (no FR/AC covers either, per research.md). This is the exact anti-pattern the test-plan's own Risk Map guidance warns against, but it is unavoidable given the PRD's silence — and `plan.md`'s own "Testing Strategy" section already accepts this as the discipline followed ("hand-derivable from the stated constants/formula ... not a re-run of the service's own live output"). Not a new issue introduced by this implementation; recorded for visibility only.
- **Fix**: None required for this change. A future PRD update pinning these constants would give a true independent oracle, but that's out of scope here (plan.md already excludes opening a PRD-update change for this).
- **Decision**: ACCEPTED-AS-RULE: Base-dose/hypoglycemia constants lack an independent PRD oracle (see context/foundation/lessons.md). Not fixed — lesson only, per user choice.

## Additional verification performed

- Both review sub-agents independently re-derived the arithmetic for all 12 new test methods against the real service formulas — all match (no oracle-problem violation via copied live output, no off-by-one).
- Confirmed no leftover Polish identifiers remain in either renamed service file (`grep` clean).
- Confirmed all 11 new `BaseDoseSuggestionServiceTest` methods follow the file's established `boot()`/`createUser()`/`createProfile()`/try-finally-cleanup pattern; no test-independence or cleanup gaps.
- Re-ran automated success criteria: `phpunit` (34/34 on the two touched test files, 153/153 full suite), `phpstan analyse` (no errors), `php-cs-fixer --dry-run` (0 files need fixing) — all green.
