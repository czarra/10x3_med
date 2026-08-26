<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Ostrzeżenie o ryzyku hipoglikemii przy wysiłku

- **Plan**: context/changes/activity-hypoglycemia-warning/plan.md
- **Mode**: Deep
- **Date**: 2026-08-26
- **Verdict**: SOUND
- **Findings**: 0 critical, 1 warning, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | WARNING |
| Plan Completeness | PASS |

## Grounding

8/8 paths ✓ (DiaryEntry.php, DiaryController.php, RatioSuggestionResult.php,
InsulinWwRatioSuggestionService.php, templates/dashboard/index.html.twig,
templates/diary/new.html.twig, config/services.yaml, security.yaml), symbols ✓
(getInsulinDose, validateActivityPairing, ActivityIntensity::{Light,Medium,Strong},
createUser/createProfile/cleanupUser, assertSelectorTextContains), brief↔plan ✓.

## Findings

### F1 — Threshold formula doesn't specify handling of `insulinDose === null`

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Blind Spots
- **Location**: Phase 1 — "Critical Implementation Details" + service Contract (`HypoglycemiaWarningService::evaluate()`)
- **Detail**: `DiaryEntry::getInsulinDose(): ?float` is nullable. The plan's formula `glycemiaMgDl - insulinDose * 45 < threshold` operates on it directly, with no explicit null handling. Every other place in the codebase doing arithmetic on `insulinDose`/`ww` guards null first — `InsulinWwRatioSuggestionService.php:99`, `BaseDoseSuggestionService.php:109` — because PHPStan (level 5, `src/`) flags arithmetic on a `float|null` operand. The plan's own test matrix assumes null-safe behavior (Light, glycemia=85, insulinDose=null → warn using 85), but the Contract never states the `?? 0.0` guard, so the implementer discovers it only when Phase 1's own `phpstan analyse` success criterion fails.
- **Fix**: Add one sentence to the Phase 1 Contract: `evaluate()` must compute `($entry->getInsulinDose() ?? 0.0) * self::INSULIN_DROP_PER_UNIT_MGDL` to avoid a PHPStan "Only numeric types are allowed in *" error and match the null-handling already implied by the test matrix.
- **Decision**: FIXED (applied to plan.md)

### F2 — Example test cases don't cover the full stated matrix

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Plan Completeness
- **Location**: Phase 4, item 1 (service unit test)
- **Detail**: The "Intent" describes a full 3 intensities × 3 threshold-position matrix (just below / exactly at / just above) plus an insulin-boost case and a no-activity case (~11 cases), but the "Przykładowe przypadki liczbowe" section lists only 6 of them (e.g. missing Medium-exactly-at-threshold, Light-just-above-threshold). Not blocking — the plan labels these as examples, and the formula + Intent sentence give the implementer enough to derive the rest — but worth flagging so Phase 4 doesn't stop at transcribing only the 6 listed cases.
- **Fix**: No plan edit required — treat the "Intent" sentence as the actual spec (full 3×3 matrix) and the 6 examples as a starting subset when writing Phase 4 tests.
- **Decision**: ACCEPTED
