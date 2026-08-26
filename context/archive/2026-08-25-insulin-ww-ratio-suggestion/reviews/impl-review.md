<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: S-03 Sugestia skorygowanego przelicznika insulina/WW

- **Plan**: context/changes/insulin-ww-ratio-suggestion/plan.md
- **Scope**: All 6 phases (full plan) — verification pass after F1-F4 fixes (commit 04004b8)
- **Date**: 2026-08-26
- **Verdict**: APPROVED
- **Findings**: 0 critical, 0 warnings, 1 observation

## Previously identified and fixed (round 1, see git history of this file at commit 04004b8)

F1 (division-by-zero/NaN in meal pairing), F2 (off-by-one in streak scan), F3 (missing validator before flush), F4 (missing composite index) were all independently re-verified in this pass and confirmed correctly fixed, each with a regression test reproducing the original failure mode. No regressions introduced by these fixes.

Two claims raised during this pass were investigated and **dismissed as non-issues**:
- A suspected "sibling" off-by-one at `BaseDoseSuggestionService.php:48` (`$dayAfterCutoff > $startDate` compared as full timestamps before `findMostRecentRun()` normalizes to midnight) — traced through by hand: since the loop always normalizes both operands to midnight before use, any time-of-day skew in this earlier comparison is neutralized; timestamp ordering across distinct calendar dates always agrees with calendar-date ordering, so the comparison cannot select the wrong day. Not a bug.
- Absence of a flash message when the validator rejects a value in `DashboardController::acceptRatio`/`acceptBaseDose` — this is intentional, mirroring the plan's own documented "no longer available (race)" path: redirect, no DB write, no flash.

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

### F5 — Validator-rejection branch has no dedicated regression test

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria (test coverage)
- **Location**: src/Controller/DashboardController.php:68, 111
- **Detail**: Both accept actions call `$validator->validate($profile)` and redirect without persisting if violations exist (the F3 fix). No test in `tests/Controller/DashboardControllerTest.php` forces the validator to actually return a violation — `grep -n "validat" tests/Controller/DashboardControllerTest.php` finds nothing. In current code this branch is unreachable in practice (the suggestion services already clamp to the entity's valid range before returning), so it functions purely as a defensive backstop against future drift between clamp bounds and entity constraints — but that backstop itself is currently unverified by any test.
- **Fix**: Add a test that forces a validator violation on the accept path (e.g. a test-only profile state or a mocked/stubbed suggestion result outside the entity's valid range) and asserts no DB write / no flash occurs, confirming the guard isn't dead code.
- **Decision**: FIXED — added `testAcceptRatioWithInvalidProfileStateMakesNoDbChanges` (tests/Controller/DashboardControllerTest.php), which persists a `PatientProfile` with `baseDose = 40` (violates `Assert\LessThanOrEqual(35)`) directly via the entity manager, then drives the ratio-accept POST. Verified to fail (red) when the `ValidatorInterface::validate($profile)` guard in `DashboardController::acceptRatio` is stubbed out, and pass (green) with the guard in place — proves the F3 backstop is live, not dead code. Full suite re-run: phpstan 0 errors, php-cs-fixer 0/50 files, phpunit 59 tests / 179 assertions, all green.

## Notes for the record (not findings — no action needed)

- All 8 worked numeric examples (4 ratio + 4 base-dose) reproduce exactly when hand-traced against the current code, including after the F1/F2 fixes.
- Automated verification re-run in full for this pass: `phpstan analyse` — 0 errors; `php-cs-fixer fix --dry-run` — 0 of 50 files need changes; `phpunit` — 58 tests, 173 assertions, all green; migrations status on `dev` — 6/6 executed, 0 new, matches `Version20260826092028` (latest).
- Nav link (`templates/base.html.twig`) is implemented as a dropdown entry rather than the flat `<li>` shown in the plan's template contract — functionally equivalent (link present, working, per Phase 5 manual criterion 5.7), a cosmetic deviation only, not scope drift.
- CSRF handling, IDOR scoping (profile always re-fetched via `findOneByUser($user)`, never trusts a posted id), authn/authz, migration reversibility (`down()` on all 3 new/altered migrations), and repository/entity pattern consistency with `DiaryEntryRepository`/`DiaryEntry` all checked out clean.
