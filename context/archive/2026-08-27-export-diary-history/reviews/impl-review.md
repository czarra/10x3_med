<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Eksport historii dziennika do CSV

- **Plan**: context/changes/export-diary-history/plan.md
- **Scope**: Phase 1 of 2, Phase 2 of 2 (full plan)
- **Date**: 2026-08-27
- **Verdict**: APPROVED
- **Findings**: 0 critical, 1 warning, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Findings

### F1 — Missing `finally` around `fclose($handle)` in the streamed export callback

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Controller/DiaryController.php:144-148
- **Detail**: The `StreamedResponse` callback opens `php://output`, calls `DiaryExportService::writeCsv()`, then closes the handle:
  ```php
  $handle = fopen('php://output', 'w');
  $diaryExportService->writeCsv($historyPage, $handle);
  fclose($handle);
  ```
  If `writeCsv()` throws, `fclose($handle)` is skipped. Real-world impact is low — `php://output` is reclaimed at request end and `fopen('php://output', 'w')` essentially never fails under a normal SAPI — but wrapping in try/finally matches the defensive style used elsewhere in the codebase (e.g., try/finally cleanup throughout the test suite).
- **Fix**: Wrap the body in `try { $diaryExportService->writeCsv($historyPage, $handle); } finally { fclose($handle); }`.
- **Decision**: FIXED — try/finally applied in src/Controller/DiaryController.php:144-150; phpstan, php-cs-fixer, and export-related phpunit tests re-verified green.

### F2 — CSV/formula injection: no defense-in-depth (not exploitable today)

- **Severity**: 📝 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Service/Export/DiaryExportService.php:36-48
- **Detail**: All exported cell values are dates, server-formatted numerics constrained by `Assert\Range` on `DiaryEntry`, or a fixed enum string (`light`/`medium`/`strong`) — none are free-text user input, so a leading `=`, `+`, `-`, or `@` cannot occur in any cell today. No CSV/formula injection is currently possible. Worth revisiting only if this service is ever extended to export free-text fields (e.g. a future "notes" column).
- **Fix**: No action needed now; revisit if free-text fields are ever added to the export.
- **Decision**: ACCEPTED-AS-RULE — recorded as "CSV export of free-text fields needs formula-injection escaping" in context/foundation/lessons.md; code left unchanged (no free-text field exists today).

## Summary

- **Plan drift**: none. Both phases match the plan's contracts verbatim (service signature, CSV format, controller route/signature/headers, template placement, all planned tests present).
- **Scope discipline**: none of the explicit non-goals (ratio/base-dose export, PDF, nav-menu link, "export whole history", date-range filter, migrations, new composer dependency) were introduced.
- **Success criteria**: `phpstan analyse` (level 5) — OK. `php-cs-fixer fix --dry-run` — clean. `phpunit` — 129/129 passing, no regressions. All manual verification steps (1.4, 1.5, 2.3–2.5) confirmed by the user.
- **Authorization**: `export()` scopes strictly to `$this->getUser()`; the only query param (`page`) is a clamped int used for pagination, not entry lookup — confirmed by `testExportOnlyIncludesRequestingUsersEntries`.
