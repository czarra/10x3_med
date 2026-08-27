<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Edit / Delete Diary Entry (S-06, FR-014)

- **Plan**: context/changes/edit-delete-diary-entry/plan.md
- **Scope**: Full plan — Phases 1–4 (all complete)
- **Date**: 2026-08-27
- **Verdict**: APPROVED
- **Findings**: 0 critical, 1 warning, 1 observation

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

### F1 — Unplanned stylesheet not named in the plan

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Scope Discipline
- **Location**: `public/css/diary-history-actions.css` (new), linked from `templates/diary/history.html.twig:7`
- **Detail**: Phase 4's "Changes Required" only names `templates/diary/history.html.twig`. The implementation also added a new 34-line stylesheet for the Edit/Delete action buttons, linked via a new `<link rel="stylesheet">` in the `stylesheets` block. Functionally this is harmless and follows the exact precedent already set by `diary-chart.css` (a per-view stylesheet, same hardcoded-hex-color convention as `diary-chart.css`'s `#0d6efd`, same `{% block stylesheets %}` wiring) — it isn't inventing a new pattern, just applying an existing one to a file the plan didn't list.
- **Fix**: Add a one-line addendum to the plan's Phase 4 "Changes Required" noting `public/css/diary-history-actions.css` as a companion file to the template change, so the plan stays an accurate record of what shipped.
- **Decision**: FIXED — addendum added to `plan.md` Phase 4 "Changes Required".

### F2 — Redundant duplicate DB lookups per history row

- **Severity**: 👁️ OBSERVATION
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality (performance)
- **Location**: `templates/diary/history.html.twig:75,78`; `src/Security/DiaryEntryVoter.php:29-42`
- **Detail**: The template calls `is_granted('DIARY_ENTRY_EDIT', entry)` and `is_granted('DIARY_ENTRY_DELETE', entry)` separately per row. `DiaryEntryVoter::voteOnAttribute()` doesn't branch on the attribute — both constants run the identical ownership + `DiaryEntryEditabilityService::isEditable()` check, and `isEditable()` issues two `findLatestByUser()` queries once an entry is inside its 24h window. So every row still within 24h costs 4 queries instead of 2. The plan's own check-order optimization (cheap 24h check first) is correctly implemented and bounds this to recent rows only, not the whole page — confirmed by `testExpiredEntryDoesNotQueryHistoryRepositories`. This is a real but narrowly-bounded inefficiency, not a correctness bug.

  Fix A ⭐ Recommended: Compute the grant once per row in the template — `{% set canManage = is_granted('DIARY_ENTRY_EDIT', entry) %}` — and reuse it for both the Edit link and the Delete form gate, since EDIT/DELETE are currently defined as identical rules.
    - Strength: Halves the query count on every recent row with a one-line template change; no risk since both attributes already resolve identically.
    - Tradeoff: If EDIT and DELETE rules are ever intentionally split in the Voter, the template's shortcut silently stops reflecting that until someone re-splits the two `is_granted()` calls back apart.
    - Confidence: HIGH — verified `voteOnAttribute()` truly ignores `$attribute` today.
    - Blind spot: None significant.

  Fix B: Leave the two independent `is_granted()` calls as-is (matches the plan's explicit instruction: "each action checks its own attribute — no shortcut to a single combined check").
    - Strength: Keeps the template literally matching what the plan specified, and stays correct automatically if EDIT/DELETE ever diverge.
    - Tradeoff: Doubles DB queries for every row inside the 24h window on every history-page load.
    - Confidence: MED — the query volume here is small (recent rows only), so real-world impact is likely negligible at current scale.
    - Blind spot: Haven't load-tested the history page with many same-day entries.
- **Decision**: FIXED via Fix A — `templates/diary/history.html.twig` now computes `canManage` once per row and reuses it for both the Edit link and Delete form gate. Verified with `vendor/bin/phpunit --filter DiaryControllerTest` (21/21 green).

## Notes

- All automated verification re-run and confirmed green: `vendor/bin/phpunit` (117 tests, 329 assertions), `vendor/bin/phpstan analyse` (no errors), `vendor/bin/php-cs-fixer fix --dry-run` (0 of 66 files need changes).
- All Phase 1–4 Progress checkboxes are `[x]` with commit SHAs; no MISSING or DRIFT findings against any planned file (service, Voter, both controller actions, both templates, all three test files) — confirmed independently by two review passes (plan-drift agent + safety/pattern agent).
- Security-critical details called out in the plan's "Critical Implementation Details" were each specifically re-verified and are correct: 404 (not 403) for both "not yours" and "locked" via manual `isGranted()` + `createNotFoundException()`; delete's CSRF check uses the single shared intention string `delete_diary_entry` (not per-id); edit's `flush()`-only/no-`persist()` pattern matches `ProfileController::edit()`. `DiaryEntryFormType` has no path to overwrite `user` or `createdAt`, and delete is a genuine hard-delete with no orphaned relations.
- "What We're NOT Doing" guardrails all respected — no soft-delete/versioning field, no re-validation against post-edit `measuredAt`, no changes to the suggestion services, no extra JS beyond the single `confirm()`, no disabled-button UI, no admin override.
